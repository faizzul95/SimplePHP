<?php

namespace App\Http\Controllers;

use Core\Http\Controller;
use Core\Http\HtmlResponse;
use Core\Http\Request;
use Core\Http\Responsable;

/**
 * Serves modal partials to the front-end over XHR.
 *
 * This was a closure in app/routes/web.php. `route:cache` cannot serialise a
 * closure, so after `php myth deploy` the route vanished from the cached index
 * and 404'd in production while continuing to work in development — the kind of
 * failure that costs an afternoon to trace.
 */
class ModalController extends Controller
{
    public function content(Request $request): Responsable
    {
        // Modals are only ever fetched by the front-end's XHR helper. Refusing
        // direct navigation keeps arbitrary partials from being rendered as
        // standalone pages.
        if (strtolower((string) $request->header('x-requested-with', '')) !== 'xmlhttprequest') {
            return $this->partial(modalPartialAlert('Invalid modal request.'), 403);
        }

        $response = renderModalPartial(
            (string) $request->input('fileName', ''),
            $request->input('dataArray', [])
        );

        return $this->partial(
            (string) ($response['content'] ?? modalPartialAlert('Unable to load modal content.')),
            (int) ($response['status'] ?? 500)
        );
    }

    private function partial(string $html, int $status): HtmlResponse
    {
        return new HtmlResponse($html, $status, ['Content-Type' => 'text/html; charset=UTF-8']);
    }
}
