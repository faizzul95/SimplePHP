<?php

// IMPORTANT: This file is part of the application
require_once '../init.php';

/*
|--------------------------------------------------------------------------
| COUNT ADMIN DASHBOARD 
|--------------------------------------------------------------------------
*/

function countAdminDashboard($request)
{
    // 0-Inactive, 1-Active, 2-Suspended, 3-Deleted, 4-Unverified	
    $data = db()->table('users')->selectRaw("
        SUM(CASE WHEN user_status = '1' THEN 1 ELSE 0 END) as userActive,
        SUM(CASE WHEN user_status = '0' THEN 1 ELSE 0 END) as userInactive,
        SUM(CASE WHEN user_status IN ('2', '3') THEN 1 ELSE 0 END) as userSuspended,
        SUM(CASE WHEN user_status = '4' THEN 1 ELSE 0 END) as userNotVerify
    ")->fetch();

    jsonResponse(['code' => 200, 'data' => $data]);
}
