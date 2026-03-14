<?php

namespace Middleware\Traits;

trait PermissionAbilitiesTrait
{
	/**
	 * Check if the current request has permission to perform the action.
	 * 
	 * IMPORTANT: Defaults to true (allowed) when x-permission header is absent.
	 * This means permission is only enforced when the frontend explicitly sends
	 * the header. Override $defaultPermission to false in your middleware if you
	 * want to deny access when the header is missing.
	 * 
	 * @return bool
	 */
	public function hasPermissionAction()
	{
		$permissionHeader = request()->header('x-permission');
		$permission = $this->defaultPermission ?? true; // configurable default

		// Access specific Axios header values
		if (hasData($permissionHeader)) {
			$permission = permission($permissionHeader);
		}

		return $permission;
	}
}
