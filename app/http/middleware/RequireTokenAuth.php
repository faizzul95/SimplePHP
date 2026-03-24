<?php

namespace App\Http\Middleware;

class RequireTokenAuth extends RequireMethodAuth
{
    protected array $methods = ['token'];
}
