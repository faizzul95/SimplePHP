<?php

declare(strict_types=1);

namespace Core\Http;

/**
 * One answer, delivered in whatever form the caller can read.
 *
 * Controllers here serve a browser and a mobile client from the same action, and
 * the two need different things back: the browser wants a redirect with a flash
 * message, the app wants JSON. Writing both meant either the Laravel boilerplate
 *
 *     return $request->expectsJson()
 *         ? response()->json(['message' => 'Saved', 'data' => $row])
 *         : redirect()->route('templates.index')->with('success', 'Saved');
 *
 * at every exit point, or — what actually happened — picking one and leaving the
 * other caller with a response it cannot use. Every controller in this
 * application picked JSON, so no form submit has ever redirected.
 *
 * A Reply carries the *answer* and decides the form at emit time:
 *
 *     return reply('Email template saved', $row)->route('email-templates.index');
 *
 * A browser gets 302 to that route with "Email template saved" flashed as
 * `success`. An app gets `{"code":200,"message":"Email template saved","data":…}`.
 * Neither branch is written twice.
 */
final class Reply implements Responsable
{
    private ?string $message = null;

    private mixed $data = null;

    private bool $hasData = false;

    private int $status = 200;

    /** @var array<string, mixed> Extra keys: JSON payload entries, or flashed values */
    private array $extra = [];

    /** @var array<string, mixed> */
    private array $errors = [];

    /** @var array<string, string> */
    private array $headers = [];

    private ?string $target = null;

    private bool $keepInput = true;

    private ?bool $forceJson = null;

    /**
     * Whether the caller wanted JSON, decided when the Reply was created.
     *
     * Resolution is lazy, so deciding at emit time meant negotiating against
     * whatever request happened to be current *then* — which in a worker, or
     * anywhere a Reply outlives the moment it was built, is a different request
     * than the one the controller was answering. The controller runs inside the
     * request it should negotiate against, so that is when the question is asked.
     */
    private ?bool $wantsJson = null;

    private ?Responsable $resolved = null;

    public static function make(?string $message = null, mixed $data = null, int $status = 200): self
    {
        $reply = new self();
        $reply->status = Emitter::normalizeStatus($status);
        $reply->wantsJson = self::callerWantsJson();

        if ($message !== null) {
            $reply->message = $message;
        }

        if ($data !== null) {
            $reply->data = $data;
            $reply->hasData = true;
        }

        return $reply;
    }

    // ─── Content ─────────────────────────────────────────────────────

    public function message(string $message): self
    {
        $clone = $this->fresh();
        $clone->message = $message;

        return $clone;
    }

    /**
     * Explicit setter so `reply('Deleted')->data(null)` can mean "the payload is
     * null" rather than "there is no payload" — make() cannot tell those apart.
     */
    public function data(mixed $data): self
    {
        $clone = $this->fresh();
        $clone->data = $data;
        $clone->hasData = true;

        return $clone;
    }

    /** The HTTP status, named `code` to match the `['code' => …]` shape already in use. */
    public function code(int $status): self
    {
        $clone = $this->fresh();
        $clone->status = Emitter::normalizeStatus($status);

        return $clone;
    }

    /**
     * Laravel's `with()`: a JSON payload key for an API caller, a flashed session
     * value for a browser. Accepts an array to set several at once.
     *
     * @param string|array<string, mixed> $key
     */
    public function with(string|array $key, mixed $value = null): self
    {
        $clone = $this->fresh();

        foreach (is_array($key) ? $key : [$key => $value] as $name => $item) {
            $name = trim((string) $name);
            if ($name !== '') {
                $clone->extra[$name] = $item;
            }
        }

        return $clone;
    }

    /**
     * Field-keyed validation errors. JSON gets an `errors` object; a browser gets
     * them flashed where validationErrors() reads them, plus the old input so the
     * form can be redrawn filled in.
     *
     * @param array<string, mixed> $errors
     */
    public function errors(array $errors): self
    {
        $clone = $this->fresh();
        $clone->errors = $errors;

        if ($clone->status < 400) {
            $clone->status = 422;
        }

        return $clone;
    }

    public function header(string $name, string $value): self
    {
        $clone = $this->fresh();
        $clone->headers[$name] = $value;

        return $clone;
    }

    // ─── The browser branch ──────────────────────────────────────────

    public function to(string $path): self
    {
        $clone = $this->fresh();
        $clone->target = $path;

        return $clone;
    }

    /**
     * @param array<string, mixed> $params
     *
     * route() answers '' for a name it does not know, which would land the user
     * on the homepage after a successful save with no indication anything was
     * wrong. In development that is a typo worth surfacing immediately; in
     * production the homepage beats a 500 on an action that already succeeded.
     */
    public function route(string $name, array $params = []): self
    {
        $path = route($name, $params);

        if ($path === '') {
            if (defined('ENVIRONMENT') && ENVIRONMENT !== 'production') {
                throw new \InvalidArgumentException(
                    sprintf('reply()->route(): no route is named [%s].', $name)
                );
            }

            \Core\Support\SafeLog::warning(sprintf(
                'reply()->route(): no route is named [%s]; redirecting to /.',
                $name
            ));
        }

        return $this->to($path === '' ? '/' : $path);
    }

    /**
     * Send the browser back where it came from — the default for a failure, so
     * this only needs saying when a success should also return to the form.
     */
    public function back(string $fallback = '/'): self
    {
        $clone = $this->fresh();
        $clone->target = null;
        $clone->extra['__reply_back_fallback'] = $fallback;

        return $clone;
    }

    /** Skip re-flashing the submitted input; use where the form holds a secret. */
    public function withoutInput(): self
    {
        $clone = $this->fresh();
        $clone->keepInput = false;

        return $clone;
    }

    // ─── Forcing a form ──────────────────────────────────────────────

    public function asJson(): self
    {
        $clone = $this->fresh();
        $clone->forceJson = true;

        return $clone;
    }

    public function asRedirect(): self
    {
        $clone = $this->fresh();
        $clone->forceJson = false;

        return $clone;
    }

    // ─── Emission ────────────────────────────────────────────────────

    public function status(): int
    {
        return $this->resolve()->status();
    }

    /** @return array<string, string> */
    public function headers(): array
    {
        return $this->resolve()->headers();
    }

    public function emitBody(): void
    {
        $this->resolve()->emitBody();
    }

    /** Throw instead of returning, for a call site that cannot return a value. */
    public function send(): never
    {
        throw new ResponseEmitted($this);
    }

    /** The JSON body, without emitting — useful in tests and for logging. */
    public function payload(): array
    {
        $payload = ['code' => $this->status];

        if ($this->message !== null) {
            $payload['message'] = $this->message;
        }

        if ($this->hasData) {
            $payload['data'] = $this->data;
        }

        if ($this->errors !== []) {
            $payload['errors'] = $this->errors;
        }

        foreach ($this->extra as $key => $value) {
            if (!str_starts_with($key, '__reply_')) {
                $payload[$key] = $value;
            }
        }

        return $payload;
    }

    private function resolve(): Responsable
    {
        return $this->resolved ??= $this->wantsJson()
            ? new JsonResponse($this->payload(), $this->status, $this->headers)
            : $this->toRedirect();
    }

    private function wantsJson(): bool
    {
        return $this->forceJson ?? $this->wantsJson ?? self::callerWantsJson();
    }

    /**
     * A caller that asked for JSON gets JSON. A caller that did not still gets
     * JSON when there is nowhere to send it — a 302 to the referer is not an
     * answer for a console command or a queued job.
     *
     * Request::current() is populated lazily by the request() helper, so reading
     * it alone meant a Reply built before anything else had touched the request
     * saw null and answered JSON — to a browser.
     */
    private static function callerWantsJson(): bool
    {
        $request = Request::current();

        if ($request instanceof Request) {
            return $request->expectsJson();
        }

        // No request in flight. Under CLI that is a command or a queued job and
        // there is nowhere to redirect; under a web SAPI it only means nothing
        // has touched request() yet, so capture one rather than answer JSON to a
        // browser by accident.
        if (PHP_SAPI === 'cli' || !function_exists('request')) {
            return true;
        }

        $request = request();

        return !$request instanceof Request || $request->expectsJson();
    }

    private function toRedirect(): Responsable
    {
        $redirector = new Redirector();
        $fallback = (string) ($this->extra['__reply_back_fallback'] ?? '/');

        $response = $this->target !== null
            ? $redirector->to($this->target, $this->redirectStatus())
            : $redirector->back($fallback, $this->redirectStatus());

        $response = $response->withHeaders($this->headers);

        // A message with no key of its own still has to reach the page. Which key
        // depends on the outcome, because a template shows them differently.
        if ($this->message !== null) {
            $response = $response->with($this->status >= 400 ? 'error' : 'success', $this->message);
        }

        foreach ($this->extra as $key => $value) {
            if (!str_starts_with($key, '__reply_')) {
                $response = $response->with($key, $value);
            }
        }

        if ($this->errors !== []) {
            $response = $response->withErrors($this->errors);
        }

        // Redrawing a rejected form empty loses everything the user typed.
        if ($this->keepInput && $this->status >= 400) {
            $response = $response->withInput();
        }

        return $response;
    }

    /**
     * A redirect is always 302/303 regardless of the logical status: 422 with a
     * Location header is not a redirect, and a browser will not follow it. The
     * logical status survives as the flash key that was chosen above.
     */
    private function redirectStatus(): int
    {
        return $this->status >= 400 ? 302 : 303;
    }

    /** Resolution is memoised, so a mutation after emit would be invisible. */
    private function fresh(): self
    {
        $clone = clone $this;
        $clone->resolved = null;

        return $clone;
    }
}
