<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EmailVerificationController extends Controller
{
    /**
     * Mark the user's email as verified using the signed link from the email.
     *
     * The link is opened from an email client, so it is authenticated by its
     * signature rather than by an API token.
     */
    public function verify(string $id, string $hash): JsonResponse
    {
        $user = User::findOrFail($id);

        abort_unless(hash_equals(sha1($user->getEmailForVerification()), $hash), 403, __('Invalid verification link.'));

        if (! $user->hasVerifiedEmail() && $user->markEmailAsVerified()) {
            event(new Verified($user));
        }

        return response()->json(['message' => __('Your email address has been verified.')]);
    }

    /**
     * Send a new verification email to the authenticated user.
     *
     * Returns 404 when email verification is disabled.
     */
    public function send(Request $request): JsonResponse
    {
        abort_unless(config('auth.verification.required'), 404);

        if ($request->user()->hasVerifiedEmail()) {
            return response()->json(['message' => __('Your email address is already verified.')]);
        }

        $request->user()->sendEmailVerificationNotification();

        return response()->json(['message' => __('A new verification link has been sent to your email address.')]);
    }
}
