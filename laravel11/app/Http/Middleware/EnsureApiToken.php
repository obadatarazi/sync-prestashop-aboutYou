<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureApiToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $configured = (string) config('app.syncbridge_api_token', env('SYNCBRIDGE_API_TOKEN', ''));
        if ($configured === '') {
            return $next($request);
        }

        $incoming = (string) ($request->header('X-Api-Token') ?? '');
        if ($incoming === '') {
            $bearer = (string) $request->bearerToken();
            $incoming = $bearer !== '' ? $bearer : $incoming;
        }
        if ($incoming === '') {
            return response()->json([
                'ok' => false,
                'error' => 'API token required. Send Authorization: Bearer <token> or X-Api-Token when SYNCBRIDGE_API_TOKEN is configured.',
            ], 401);
        }

        if (!hash_equals($configured, $incoming)) {
            return response()->json([
                'ok' => false,
                'error' => 'Invalid API token.',
            ], 401);
        }

        return $next($request);
    }
}
