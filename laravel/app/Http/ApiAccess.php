<?php

namespace App\Http;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class ApiAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = config('homer.api_token');
        if (! is_string($expected) || $expected === '' || ! hash_equals($expected, (string) $request->bearerToken())) {
            return response()->json(['errors' => [[
                'code' => 'not_authenticated',
                'source' => 'detail',
                'message' => 'Authentication credentials were not provided or are invalid.',
            ]]], 401, ['WWW-Authenticate' => 'Bearer']);
        }

        return $next($request);
    }
}
