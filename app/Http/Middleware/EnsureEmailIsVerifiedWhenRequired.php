<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureEmailIsVerifiedWhenRequired
{
    /**
     * Block unverified users when email verification is enabled.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (config('auth.verification.required') && $user && ! $user->hasVerifiedEmail()) {
            abort(Response::HTTP_FORBIDDEN, __('Your email address is not verified.'));
        }

        return $next($request);
    }
}
