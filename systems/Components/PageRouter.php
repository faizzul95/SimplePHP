<?php

namespace Components;

class PageRouter
{
    private $menuList;
    private $authPages = ['login', 'register', 'forgot', 'reset_password'];

    public function __construct($menuList)
    {
        $this->menuList = $menuList;
    }

    /**
     * Main routing method
     */
    public function route()
    {
        try {
            $page = $this->sanitizeInput(request()->input('_p'));
            $spage = $this->sanitizeInput(request()->input('_sp'));

            // Handle authentication pages (no login required)
            if ($this->handleAuthPages($page)) {
                return;
            }

            // Handle subpages
            if (!empty($page) && !empty($spage)) {
                if ($this->handleSubPage($page, $spage)) {
                    return;
                }
            }

            // Handle main pages
            if (!empty($page)) {
                if ($this->handleMainPage($page)) {
                    return;
                }
            }

            // No valid page found
            $this->show404();
        } catch (\Exception $e) {
            logger()->logException($e);
            http_response_code(500);
            echo "500 - Internal Server Error";
            exit;
        }
    }

    /**
     * Sanitize input to prevent path traversal attacks
     */
    private function sanitizeInput($input)
    {
        if (empty($input)) {
            return null;
        }

        // Remove dangerous characters
        $input = preg_replace('/[^a-zA-Z0-9_-]/', '', $input);

        // Prevent path traversal
        $input = str_replace(['..', '/', '\\'], '', $input);

        return $input;
    }

    /**
     * Handle authentication pages
     */
    private function handleAuthPages($page)
    {
        if (empty($page) || !in_array($page, $this->authPages)) {
            return false;
        }

        $filePath = "views/auth/{$page}.php";

        if ($this->includeFile($filePath)) {
            return true;
        }

        error_log("Auth page file not found: {$filePath}");
        return false;
    }

    /**
     * Handle subpages
     */
    private function handleSubPage($page, $spage)
    {
        if (!isset($this->menuList[$page]['subpage'][$spage])) {
            return false;
        }

        $subPageConfig = $this->menuList[$page]['subpage'][$spage];
        $filePath = $subPageConfig['file'] ?? null;

        if (empty($filePath)) {
            error_log("Subpage file path not configured for: {$page}/{$spage}");
            return false;
        }

        if (!$this->fileExists($filePath)) {
            error_log("Subpage file not found: {$filePath}");
            return false;
        }

        // Check authentication
        $loginRequired = $subPageConfig['authenticate'] ?? false;
        if (!$this->checkAuthentication($loginRequired)) {
            return false;
        }

        // Set page variables
        $this->setPageVariables($page, $spage, $subPageConfig);

        return $this->includeFile($filePath);
    }

    /**
     * Handle main pages
     */
    private function handleMainPage($page)
    {
        if (!isset($this->menuList[$page])) {
            return false;
        }

        $pageConfig = $this->menuList[$page];
        $filePath = $pageConfig['file'] ?? null;

        if (empty($filePath)) {
            error_log("Main page file path not configured for: {$page}");
            return false;
        }

        if (!$this->fileExists($filePath)) {
            error_log("Main page file not found: {$filePath}");
            return false;
        }

        // Check authentication
        $loginRequired = $pageConfig['authenticate'] ?? false;
        if (!$this->checkAuthentication($loginRequired)) {
            return false;
        }

        // Set page variables
        $this->setPageVariables($page, null, $pageConfig);

        return $this->includeFile($filePath);
    }

    /**
     * Check if file exists securely
     */
    private function fileExists($filePath)
    {
        $fullPath = ROOT_DIR . $filePath;

        // Security check: ensure file is within ROOT_DIR
        $realPath = realpath($fullPath);
        $realRootDir = realpath(ROOT_DIR);

        if ($realPath === false || $realRootDir === false) {
            return false;
        }

        // Check if file is within allowed directory
        if (strpos($realPath, $realRootDir) !== 0) {
            error_log("Security violation: Attempted to access file outside ROOT DIRECTORY: {$filePath}");
            return false;
        }

        return file_exists($fullPath);
    }

    /**
     * Include file safely
     */
    private function includeFile($filePath)
    {
        if (!$this->fileExists($filePath)) {
            return false;
        }

        $fullPath = ROOT_DIR . $filePath;

        try {
            include_once $fullPath;
            exit;
        } catch (\Exception $e) {
            logger()->logException($e);
            return false;
        }
    }

    /**
     * Check authentication
     */
    private function checkAuthentication($loginRequired)
    {
        if (!$loginRequired) {
            return true;
        }

        try {
            isLogin($loginRequired, 'isLoggedIn', REDIRECT_LOGIN);
            return true;
        } catch (\Exception $e) {
            logger()->logException($e);
            return false;
        }
    }

    /**
     * Set page variables
     */
    private function setPageVariables($page, $spage = null, $config = [])
    {
        global $titlePage, $titleSubPage, $currentPage, $currentSubPage, $permission, $menuList;

        $currentPage = $page;
        $currentSubPage = $spage;
        $menuList = $this->menuList;

        $titleSubPage = null;

        if (!empty($spage)) {
            // Main page variable
            $titlePage = $menuList[$page]['desc'] ?? '';

            // Subpage variables
            $titleSubPage = $config['desc'] ?? '';
            $permission = $config['permission'] ?? null;
        } else {
            // Main page variables
            $titlePage = $config['desc'] ?? '';
            $permission = $config['permission'] ?? null;
        }
    }

    /**
     * Show 404 error
     */
    private function show404()
    {
        if (function_exists('show_404')) {
            show_404();
        } else {
            http_response_code(404);
            echo "404 - Page Not Found";
        }
        exit;
    }
}
