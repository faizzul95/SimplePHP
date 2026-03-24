<?php

namespace App\Http\Middleware;

class RequireBasicAuth extends RequireMethodAuth
{
    protected array $methods = ['basic'];
    protected bool $sendBasicChallenge = true;
}
