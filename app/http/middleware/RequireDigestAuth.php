<?php

namespace App\Http\Middleware;

class RequireDigestAuth extends RequireMethodAuth
{
    protected array $methods = ['digest'];
    protected bool $sendDigestChallenge = true;
}
