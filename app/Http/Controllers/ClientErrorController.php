<?php

namespace App\Http\Controllers;

use App\Actions\RecordClientError;
use App\Models\ClientError;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/**
 * Where the browser reports its own JavaScript errors.
 *
 * OPEN TO GUESTS on purpose: a broken public page — Home, a game, the lobby,
 * the invite landing — is the report worth having most, and every one of those
 * renders for somebody with no session. The exposure is bounded from three
 * sides instead: `throttle` caps the requests, the Redis dedupe caps the rows,
 * and everything written is truncated to a declared width.
 *
 * Nothing here is trusted. The fingerprint is computed server-side, the user
 * comes from the session rather than the payload, and the page is reduced to a
 * PATH — a query string is where a signed link or an invite code would ride in.
 */
class ClientErrorController
{
    public function __invoke(Request $request, RecordClientError $record): Response
    {
        // Generous ceilings that TRUNCATE rather than tight ones that reject:
        // a stack trace longer than expected is still a bug report, and a 422
        // would throw away the only witness the bug has.
        $data = $request->validate([
            'kind' => ['required', Rule::in([ClientError::ERROR, ClientError::REJECTION])],
            'message' => ['required', 'string', 'max:2000'],
            'source' => ['nullable', 'string', 'max:1000'],
            'line' => ['nullable', 'integer', 'min:0', 'max:4294967295'],
            'col' => ['nullable', 'integer', 'min:0', 'max:4294967295'],
            'stack' => ['nullable', 'string', 'max:20000'],
            'path' => ['nullable', 'string', 'max:2000'],
            'viewport' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'standalone' => ['nullable', 'boolean'],
        ]);

        $record->handle([
            'kind' => $data['kind'],
            'message' => mb_substr($data['message'], 0, 500),
            'source' => isset($data['source']) ? mb_substr($data['source'], 0, 500) : null,
            'line' => $data['line'] ?? null,
            'col' => $data['col'] ?? null,
            'stack' => isset($data['stack']) ? mb_substr($data['stack'], 0, 5000) : null,
            'path' => mb_substr((string) parse_url($data['path'] ?? '', PHP_URL_PATH), 0, 255) ?: null,
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 255) ?: null,
            'viewport' => $data['viewport'] ?? null,
            'standalone' => (bool) ($data['standalone'] ?? false),
        ], $request->user());

        return response()->noContent();
    }
}
