    <!-- important -->
    <?php

    require_once 'init.php';

    $page = request()->input('_rp');
    $spage = request()->input('_sp');

    // Use to render page without login checking
    if (!empty($page) && in_array($page, ['login', 'register', 'forgot', 'reset_password'])) {
        $filePath = "views/auth/{$page}.php";
        if (!empty($filePath) && file_exists(ROOT_DIR . $filePath)) {
            include_once ROOT_DIR . $filePath;
            exit;
        }
    }

    if (!empty($page) && !empty($spage)) {
        $filePath = $menuList[$page]['subpage'][$spage]['file'] ?? null;
        if (!empty($filePath) && file_exists(ROOT_DIR . $filePath)) {
            $loginRequired = $menuList[$page]['subpage'][$spage]['authenticate'] ?? false;

            // Check if user not login, then redirect to page login
            isLogin($loginRequired, 'isLoggedIn', REDIRECT_LOGIN);

            $titlePage = $menuList[$page]['subpage'][$spage]['desc'] ?? '';
            $currentPage = $menuList[$page]['currentPage'] ?? '';
            $currentSubPage = $menuList[$page]['subpage'][$spage]['currentSubPage'] ?? null;
            $permission = $menuList[$page]['subpage'][$spage]['permission'] ?? null; // No specific permission 

            include_once ROOT_DIR . $filePath;
            exit;
        }
    } else if (!empty($page)) {
        $filePath = $menuList[$page]['file'] ?? null;
        if (!empty($filePath) && file_exists(ROOT_DIR . $filePath)) {
            $loginRequired = $menuList[$page]['authenticate'] ?? false;
            
            // Check if user not login, then redirect to page login
            isLogin($loginRequired, 'isLoggedIn', REDIRECT_LOGIN);

            $titlePage = $menuList[$page]['desc'] ?? '';
            $currentPage = $menuList[$page]['currentPage'] ?? '';
            $currentSubPage = null;
            $permission = $menuList[$page]['permission'] ?? null; // No specific permission 

            include_once ROOT_DIR . $filePath;
            exit;
        }
    }

    show_404();

    ?>