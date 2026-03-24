<?php

namespace App\Http\Controllers;

use Core\Http\Controller;
use Core\Http\Request;

class DashboardController extends Controller
{
    public function __construct()
    {
        parent::__construct();
    }

    public function index(): void
    {
        $this->setPageState('dashboard', null, null, 'Dashboard', 'Dashboard');
        $this->view('dashboard.admin1');
    }

    public function countAdminDashboard(Request $request): void
    {
        $data = db()->table('users')->selectRaw("
            SUM(CASE WHEN user_status = '1' THEN 1 ELSE 0 END) as userActive,
            SUM(CASE WHEN user_status = '0' THEN 1 ELSE 0 END) as userInactive,
            SUM(CASE WHEN user_status IN ('2', '3') THEN 1 ELSE 0 END) as userSuspended,
            SUM(CASE WHEN user_status = '4' THEN 1 ELSE 0 END) as userNotVerify
        ")->fetch();

        foreach ($data as $key => $value) {
            $data[$key] = number_format($value);
        }

        jsonResponse(['code' => 200, 'data' => $data]);
    }
}
