<?php

namespace Middleware;

class CSRFMiddleware
{
    public function run($args = null)
    {
        if (!csrf()->validate()) {
            jsonResponse([
                'code' => 403,
                'message' => 'CSRF validation failed'
            ], 422);
            return;
        }
    }
}
