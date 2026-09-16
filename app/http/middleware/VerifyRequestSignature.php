<?php

namespace App\Http\Middleware;

use Core\Http\Abort;
use Core\Http\Middleware\MiddlewareInterface;
use Core\Http\Request;
use Core\Security\RequestSignature;

/**
 * The mobile counterpart to CSRF.
 *
 * Applied as `signed`, after the auth middleware — the signing key is the bearer
 * token the caller presented, so there is nothing to verify against until the
 * token has been accepted.
 *
 *     $router->post('/orders', [...])->middleware('signed');
 *
 * See Core\Security\RequestSignature for why a token client needs this rather
 * than a CSRF token.
 */
class VerifyRequestSignature implements MiddlewareInterface
{
    /** Methods with no body worth protecting; overridable with `signed:all`. */
    private const READ_METHODS = ['GET', 'HEAD', 'OPTIONS'];

    private bool $signAllMethods = false;

    public function setParameters(array $parameters): void
    {
        $modes = array_map(static fn($value): string => strtolower(trim((string) $value)), $parameters);
        $this->signAllMethods = in_array('all', $modes, true);
    }

    public function handle(Request $request, callable $next)
    {
        $config = (array) config('security.request_signing', []);

        if (($config['enabled'] ?? false) !== true) {
            return $next($request);
        }

        if (!$this->signAllMethods && in_array(strtoupper($request->method()), self::READ_METHODS, true)) {
            return $next($request);
        }

        $key = $this->signingKey($request, $config);
        if ($key === null) {
            // No key means the caller presented no token and no shared secret is
            // configured. Refusing is the only safe answer: passing through would
            // make the middleware silently optional.
            Abort::problem($request, 401, 'Request signing is required but no signing credential was presented.');
        }

        $signature = trim((string) $request->header($config['header'] ?? 'X-Signature', ''));
        $timestamp = trim((string) $request->header($config['timestamp_header'] ?? 'X-Timestamp', ''));
        $nonce = trim((string) $request->header($config['nonce_header'] ?? 'X-Nonce', ''));

        if ($signature === '' || $timestamp === '' || $nonce === '') {
            Abort::problem($request, 401, 'Request signature headers are missing.');
        }

        $tolerance = (int) ($config['tolerance_seconds'] ?? 300);
        if (!RequestSignature::withinTolerance($timestamp, $tolerance)) {
            Abort::problem($request, 401, 'Request timestamp is outside the accepted window.', [
                'tolerance_seconds' => $tolerance,
            ]);
        }

        if (!RequestSignature::isWellFormedNonce($nonce)) {
            Abort::problem($request, 401, 'Request nonce is malformed.');
        }

        $canonical = RequestSignature::canonical(
            $request->method(),
            $request->path(),
            $timestamp,
            $nonce,
            $request->rawBody()
        );

        $algorithm = (string) ($config['algorithm'] ?? 'sha256');

        if (!RequestSignature::matches($signature, $canonical, $key, $algorithm)) {
            Abort::problem($request, 401, 'Request signature does not match.');
        }

        // Last, and only after the signature is known good: burning a nonce for a
        // request that fails verification would let an attacker pre-burn the
        // nonces a legitimate client is about to send.
        if (!$this->burnNonce($nonce, $key, $config)) {
            Abort::problem($request, 409, 'This request has already been submitted.');
        }

        return $next($request);
    }

    /**
     * The caller's own bearer token, which is per-device and revocable.
     *
     * A shared secret is the fallback for endpoints reached before authentication
     * — a login route being the obvious one. It is weaker, because it ships inside
     * every copy of the app, so it is opt-in rather than the default.
     */
    private function signingKey(Request $request, array $config): ?string
    {
        $token = auth()->bearerToken();
        if (is_string($token) && $token !== '') {
            return $token;
        }

        $shared = trim((string) ($config['shared_secret'] ?? ''));

        return $shared !== '' ? $shared : null;
    }

    /**
     * Record the nonce, and report whether it was already spent.
     *
     * add() is the atomic "set if absent" every store implements, so two copies
     * of the same request racing each other cannot both win. The TTL only needs
     * to outlive the timestamp tolerance: past that the clock check rejects the
     * replay anyway, so keeping nonces longer buys nothing and costs memory.
     */
    private function burnNonce(string $nonce, string $key, array $config): bool
    {
        $ttl = max(
            (int) ($config['tolerance_seconds'] ?? 300) * 2,
            (int) ($config['nonce_ttl'] ?? 600)
        );

        try {
            return (bool) cache()->add(RequestSignature::nonceCacheKey($nonce, $key), 1, $ttl);
        } catch (\Throwable $e) {
            \Core\Support\SafeLog::exception($e, 'Request signature nonce store unavailable');

            // Fail open on the nonce specifically. The signature and the clock
            // have already passed, so the remaining exposure is a replay inside
            // the tolerance window — which is a far better outcome than a broken
            // cache taking every signed endpoint offline.
            return true;
        }
    }
}
