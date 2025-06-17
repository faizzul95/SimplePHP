<?php

namespace Middleware\Traits;

trait XssProtectionTrait
{
	/**
	 * Function to check if has xss code in $_POST or $_GET
	 */
	public function isXssAttack(): bool
	{
		return request()->detectXss(); // basic checking
	}
}
