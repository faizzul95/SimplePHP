<?php

namespace App\Http\Middleware;

class RequireOAuthAuth extends RequireMethodAuth
{
    protected array $methods = ['oauth'];
    protected bool $redirectOnFailure = true;
}
