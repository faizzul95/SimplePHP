<?php

namespace App\Http\Middleware;

use Core\Http\Middleware\MiddlewareInterface;
use Core\Http\Request;
use Core\Http\Response;

class SetResponseCache implements MiddlewareInterface
{
    private int $seconds = 0;
    private bool $public = true;
    private bool $immutable = false;
    private bool $noStore = false;

    public function setParameters(array $parameters): void
    {
        // Syntax:
        // cache.headers:60
        // cache.headers:60,public,immutable
        // cache.headers:0,private,no-store
        if (isset($parameters[0]) && is_numeric($parameters[0])) {
            $this->seconds = max(0, (int) $parameters[0]);
        }

        foreach ($parameters as $param) {
            $token = strtolower(trim((string) $param));
            if ($token === 'public') {
                $this->public = true;
            } elseif ($token === 'private') {
                $this->public = false;
            } elseif ($token === 'immutable') {
                $this->immutable = true;
            } elseif ($token === 'no-store' || $token === 'nostore') {
                $this->noStore = true;
            }
        }
    }

    public function handle(Request $request, callable $next)
    {
        if ($this->noStore) {
            Response::noCache();
        } else {
            Response::cache($this->seconds, $this->public, $this->immutable);
        }

        return $next($request);
    }
}
