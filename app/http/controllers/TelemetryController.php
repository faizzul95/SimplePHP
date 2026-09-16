<?php

namespace App\Http\Controllers;

use Core\Http\Controller;
use Core\Http\Request;
use Core\Telemetry\Entry;
use Core\Telemetry\Gate;

/**
 * Serves recorded entries to the debug bar.
 *
 * Gated by the same Gate that decides whether anything is recorded, and gated
 * again here: recording being on for user 42 must not mean user 43 can read
 * user 42's request bodies. When the gate says no this returns 404 rather than
 * 403, so the endpoint's existence is not advertised on a site where telemetry
 * is switched off.
 */
class TelemetryController extends Controller
{
    public function index(Request $request): array
    {
        $recorder = telemetry();

        if (!$recorder->enabled()) {
            return ['code' => 404, 'message' => 'Not found'];
        }

        $filters = [];

        $after = $request->input('after');
        if (is_numeric($after) && (float) $after > 0) {
            $filters['after'] = (float) $after;
        }

        $type = (string) ($request->input('type') ?? '');
        if ($type !== '' && in_array($type, Entry::TYPES, true)) {
            $filters['types'] = [$type];
        }

        $requestId = (string) ($request->input('request_id') ?? '');
        if ($requestId !== '') {
            $filters['request_id'] = $requestId;
        }

        $search = (string) ($request->input('search') ?? '');
        if ($search !== '') {
            $filters['search'] = substr($search, 0, 200);
        }

        /*
        | In per-user mode a reader only ever sees their own entries. Without
        | this, adding your id to the allowlist would let you read every other
        | recorded user's request payloads through this endpoint.
        */
        if ($recorder->gate()->mode() === Gate::MODE_USERS) {
            $filters['user_id'] = (int) $recorder->userId();
        }

        $limit = (int) ($request->input('limit') ?? 200);

        $entries = array_map(
            static fn(Entry $entry): array => $entry->toArray(),
            $recorder->store()->read(max(1, min($limit, 500)), $filters)
        );

        return [
            'code' => 200,
            'message' => 'OK',
            'data' => [
                'entries' => $entries,
                'request_id' => $recorder->requestId(),
                'mode' => $recorder->gate()->mode(),
            ],
        ];
    }

    /** Drop everything on disk. Same gate as reading. */
    public function destroy(Request $request): array
    {
        $recorder = telemetry();

        if (!$recorder->enabled()) {
            return ['code' => 404, 'message' => 'Not found'];
        }

        return [
            'code' => 200,
            'message' => 'Telemetry cleared',
            'data' => ['files_removed' => $recorder->store()->purge()],
        ];
    }
}
