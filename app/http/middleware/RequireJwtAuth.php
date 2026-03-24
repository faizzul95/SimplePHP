<?php

namespace App\Http\Middleware;

class RequireJwtAuth extends RequireMethodAuth
{
    protected array $methods = ['jwt'];
}
