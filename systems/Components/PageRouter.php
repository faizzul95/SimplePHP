<?php

namespace Components;

class PageRouter
{
    private $authPages = ['login', 'signin', 'signup', 'register', 'forgot', 'reset_password'];
    private $config;

    public function __construct($config)
    {
        if (empty($config)) {
            throw new \Exception("Configuration array is required");
        }

        $this->config = $config;
    }

    /**
     * Main routing method
     */
    public function route()
    {
        try {
            $page = $this->sanitizeInput($this->config['page']);
            $spage = $this->sanitizeInput($this->config['subpage']);

            // Check if page is active
            if (!$this->isPageActive()) {
                $this->show500();
                return;
            }

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

        $filePath = "app/views/auth/{$page}.php";

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
        $filePath = $this->config['file'] ?? null;

        if (empty($filePath)) {
            error_log("File path not configured for: {$page}/{$spage}");
            return false;
        }

        if (!$this->fileExists($filePath)) {
            error_log("File not found: {$filePath}");
            return false;
        }

        // Check authentication
        $loginRequired = $this->config['authenticate'] ?? false;
        if (!$this->checkAuthentication($loginRequired)) {
            return false;
        }

        // Set page variables
        $this->setPageVariables($page, $spage);

        return $this->includeFile($filePath);
    }

    /**
     * Handle main pages
     */
    private function handleMainPage($page)
    {
        $filePath = $this->config['file'] ?? null;

        if (empty($filePath)) {
            error_log("File path not configured for: {$page}");
            return false;
        }

        if (!$this->fileExists($filePath)) {
            error_log("File not found: {$filePath}");
            return false;
        }

        // Check authentication
        $loginRequired = $this->config['authenticate'] ?? false;
        if (!$this->checkAuthentication($loginRequired)) {
            return false;
        }

        // Set page variables
        $this->setPageVariables($page, null);

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
    private function setPageVariables($page, $spage = null)
    {
        global $titlePage, $titleSubPage, $currentPage, $currentSubPage, $permission;

        $currentPage = $page;
        $currentSubPage = $spage;
        $permission = $this->config['permission'] ?? null;

        if (!empty($spage)) {
            $titlePage = $this->config['desc'] ?? '';
            $titleSubPage = $this->config['desc'] ?? '';
        } else {
            $titlePage = $this->config['desc'] ?? '';
        }
    }

    /**
     * Check if the current page is active
     * @return bool
     */
    private function isPageActive()
    {
        // Skip active check for auth pages
        $page = $this->config['page'] ?? null;
        if (in_array($page, $this->authPages)) {
            return true;
        }

        return $this->config['active'] ?? true;
    }

    /**
     * Show 404 error
     */
    private function showError($code, $title, $message, $image = null)
    {
        http_response_code($code);

        $errorCode = $code;
        $errorTitle = $title;
        $errorMessage = $message;
        $errorImage = $image;

        $errorLayout = ROOT_DIR . 'app/views/errors/layouts/error.php';

        if (file_exists($errorLayout)) {
            include $errorLayout;
        } else {
            echo "$code - $title";
        }
        exit;
    }

    /**
     * Show 404 error
     */
    private function show404()
    {
        $this->showError(
            404,
            'Page Not Found',
            'Sorry, the page you are looking for does not exist or has been moved.',
            base_url('/public/sneat/img/illustrations/page-misc-error-light.png')
        );
    }

    /**
     * Show 500 error
     */
    private function show500()
    {
        $this->showError(
            500,
            'Internal Server Error',
            'Oops! Something went wrong on our end. Please try again later.',
            base_url('/public/sneat/img/illustrations/page-misc-error-light.png')
        );
    }
}
