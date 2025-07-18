<?php

namespace Middleware;

use Middleware\Traits\RateLimitingThrottleTrait;
use Middleware\Traits\XssProtectionTrait;
use Middleware\Traits\PermissionAbilitiesTrait;
use Middleware\Traits\SecurityHeadersTrait;

class XMLHttpRequestMiddleware
{
    use RateLimitingThrottleTrait;
    use XssProtectionTrait;
    use PermissionAbilitiesTrait;
    use SecurityHeadersTrait;

    public function run($args = null)
    {
        $this->set_security_headers();

        if (isAjax()) {
            $action = request()->input('action');

            // Initialize controllers and fileName to null
            $controllers = null;
            $fileName = null;

            // Find the index of 'controllers' and get the next segment as file name
            $allParts = request()->AllSegments();
            $controllersIndex = array_search('controllers', $allParts);

            if ($controllersIndex !== false && isset($allParts[$controllersIndex + 1])) {
                $controllers = $allParts[$controllersIndex];
                $fileName = $allParts[$controllersIndex + 1];
            }

            if ($action !== 'modal' && $controllers === 'controllers' && in_array(request()->method(), ['POST', 'GET'])) {
                $this->security();
                $this->next($controllers, $action, $fileName);
            }
        }
    }

    public function next($controllers, $action, $file)
    {
        $fileNoExt = pathinfo($file, PATHINFO_FILENAME);
        $filePath = ROOT_DIR . $controllers . DIRECTORY_SEPARATOR . $file;

        if (file_exists($filePath)) {
            // Only require if not already included
            if (!in_array(realpath($filePath), get_included_files())) {
                require $filePath;
            }

            if (hasData($action) && function_exists($action)) {
                
                // Check for the CSRF token
                if (!csrf()->validate($fileNoExt . DIRECTORY_SEPARATOR . $action)) {
                    jsonResponse([
                        'code' => 422,
                        'message' => 'CSRF token mismatch.'
                    ], 422);
                    return;
                }

                call_user_func($action, request()->unsafe()->all());
            } elseif (!hasData($action)) {
                dd("Action is not defined in callApi.");
            } elseif (!function_exists($action)) {
                dd("Function '$action' does not exist");
            }
        }
    }

    public function security()
    {
        global $config;
        $security = $config['security'] ?? [];

        // Throttle request if enabled
        if (!empty($security['throttle_request']) && $security['throttle_request'] === true) {
            $this->isRateLimiting();
        }

        // XSS protection if enabled
        if (!empty($security['xss_request']) && $security['xss_request'] === true) {
            $whiteListField = request()->input('_whitelist_field');
            if ($this->isXssAttack($whiteListField)) {
                jsonResponse([
                    'code' => 422,
                    'message' => 'Protection against <b><i> Cross-site scripting (XSS) </i></b> activated!'
                ], 422);
                return;
            }
        }

        // Permission check if enabled
        if (!empty($security['permission_request']) && $security['permission_request'] === true) {
            if (!$this->hasPermissionAction()) {
                jsonResponse([
                    'code' => 403,
                    'message' => 'You are not authorized to perform this action'
                ], 403);
                return;
            }
        }
    }
}
