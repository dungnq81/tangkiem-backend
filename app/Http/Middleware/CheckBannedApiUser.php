<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * API-safe banned user check.
 *
 * Unlike CheckBannedUser (web), this does NOT touch sessions or CSRF.
 * It simply returns 403 JSON and revokes the current Sanctum token.
 *
 * Applied to API routes that require auth:sanctum.
 */
class CheckBannedApiUser
{
    public function handle(Request $request, Closure $next): Response
    {
        if (Auth::check()) {
            /** @var \App\Models\User $user */
            $user = Auth::user();

            if ($user->isBanned()) {
                // Revoke current access token (if using token auth)
                $currentToken = $user->currentAccessToken();
                if ($currentToken && method_exists($currentToken, 'delete')) {
                    $currentToken->delete();
                }

                return response()->json([
                    'success' => false,
                    'message' => $user->getBanMessage(),
                    'error'   => 'banned',
                ], 403);
            }
        }

        return $next($request);
    }
}
