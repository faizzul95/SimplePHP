<?php

namespace Core\Http;

class Redirector
{
    public function to(string $path = '/', int $status = 302): RedirectResponse
    {
        return new RedirectResponse($this->normalizeLocalTarget($path), $status);
    }

    public function route(string $name, array $params = [], int $status = 302): RedirectResponse
    {
        $target = route($name, $params, true);
        if ($target === '') {
            $target = url('/');
        }

        return new RedirectResponse($target, $status);
    }

    public function away(string $url, int $status = 302): RedirectResponse
    {
        return new RedirectResponse(Response::sanitizeRedirectTarget($url, true), $status, [], true);
    }

    public function back(string $fallback = '/', int $status = 302): RedirectResponse
    {
        $referer = trim((string) ($_SERVER['HTTP_REFERER'] ?? ''));
        if ($referer !== '') {
            $normalized = Response::sanitizeRedirectTarget($referer, false);
            if ($normalized !== '/') {
                return new RedirectResponse($normalized, $status);
            }
        }

        return new RedirectResponse($this->normalizeLocalTarget($fallback), $status);
    }

    private function normalizeLocalTarget(string $path): string
    {
        $path = trim($path);
        if ($path === '') {
            return url('/');
        }

        if (preg_match('#^https?://#i', $path) === 1) {
            return Response::sanitizeRedirectTarget($path, false);
        }

        return Response::sanitizeRedirectTarget(url(ltrim($path, '/')), false);
    }
}