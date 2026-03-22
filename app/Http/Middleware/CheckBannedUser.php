<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware to check if user is banned.
 *
 * Handles both permanent bans (is_banned = true) and
 * temporary bans (banned_until is in the future).
 * Logs out banned users and returns a 403 response.
 */
class CheckBannedUser
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (Auth::check()) {
            /** @var \App\Models\User $user */
            $user = Auth::user();

            if ($user->isBanned()) {
                // Get ban message before logout
                $message = $user->getBanMessage();

                // Logout and invalidate session
                Auth::logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();

                // Return 403 Forbidden with ban message
                abort(403, $message);
            }
        }

        return $next($request);
    }
}
