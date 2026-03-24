<?php

namespace App\Http\Middleware;

class RequireApiKeyAuth extends RequireMethodAuth
{
    protected array $methods = ['api_key'];
}
