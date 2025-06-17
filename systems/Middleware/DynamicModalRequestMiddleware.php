<?php

namespace Middleware;

use Middleware\Traits\SecurityHeadersTrait;

class DynamicModalRequestMiddleware
{
    use SecurityHeadersTrait;

    public function run($args = null)
    {
        $this->set_security_headers();
        $this->next();
    }

    public function next()
    {
        $filePath = request()->input('fileName', null);
        if (!empty($filePath)) {
            $data = hasData($_POST, 'dataArray', true);

            if (file_exists($filePath)) {
                $opts = [
                    'http' => [
                        'method' => 'POST',
                        'header' => 'Content-Type: application/x-www-form-urlencoded',
                        'content' => hasData($data) ? http_build_query($data) : null,
                    ],
                ];

                $context = stream_context_create($opts);
                echo file_get_contents($filePath, false, $context);
            } else {
                echo '<div class="alert alert-danger" role="alert">
                    File <b><i>' . $filePath . '</i></b> does not exist.
                </div>';
            }
        }
    }
}
