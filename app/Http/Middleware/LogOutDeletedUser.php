<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class LogOutDeletedUser
{
    /**
     * End the session of a user who deleted the account, for example a session that is
     * still open on another device (BR-U5). The user goes to the login page with no
     * message. A JSON request gets 401, like the `auth` middleware gives.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->isAnonymized()) {
            Auth::guard('web')->logoutCurrentDevice();

            $request->session()->invalidate();
            $request->session()->regenerateToken();

            if ($request->expectsJson()) {
                abort(401);
            }

            return to_route('login');
        }

        return $next($request);
    }
}
