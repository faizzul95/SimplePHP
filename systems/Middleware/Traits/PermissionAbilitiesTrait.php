<?php

namespace Middleware\Traits;

trait PermissionAbilitiesTrait
{
	public function hasPermissionAction()
	{
		$permissionHeader = request()->header('x-permission');
		$permission = true; // set true if no header x-permission to validate

		// Access specific Axios header values
		if (hasData($permissionHeader)) {
			$permission = permission($permissionHeader);
		}

		return $permission;
	}
}
