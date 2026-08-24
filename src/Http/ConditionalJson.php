<?php

declare(strict_types=1);

namespace Gait\FilamentMobile\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/** Content-hashed JSON response used by pollable read endpoints. */
final class ConditionalJson
{
    /** @param array<string, mixed> $body */
    public static function respond(Request $request, array $body): JsonResponse
    {
        $encoded = json_encode($body);

        if ($encoded === false) {
            throw new RuntimeException('Could not encode a mobile response to compute its ETag: ' . json_last_error_msg());
        }

        $etag = 'W/"' . sha1($encoded) . '"';
        $header = $request->headers->get('If-None-Match');

        if ($header !== null && self::matches($header, $etag)) {
            return response()->json(null, 304)->header('ETag', $etag);
        }

        return response()->json($body)->header('ETag', $etag);
    }

    private static function matches(string $header, string $etag): bool
    {
        $bare = self::stripWeak($etag);

        foreach (explode(',', $header) as $candidate) {
            $candidate = trim($candidate);

            if ($candidate === '*' || self::stripWeak($candidate) === $bare) {
                return true;
            }
        }

        return false;
    }

    private static function stripWeak(string $value): string
    {
        return str_starts_with($value, 'W/') ? substr($value, 2) : $value;
    }
}
