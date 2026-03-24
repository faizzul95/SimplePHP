<?php

namespace App\Http\Middleware;

class RequireOAuth2Auth extends RequireMethodAuth
{
    protected array $methods = ['oauth2'];
}
