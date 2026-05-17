<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Mail\VerifyEmail;
use App\Models\EmailVerificationToken;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

class EmailVerificationController extends Controller
{
    use ApiResponse;

    public function verify(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'token' => 'required|string',
        ]);

        $token = EmailVerificationToken::where('token', $validated['token'])->first();

        if (!$token || !$token->user) {
            throw ValidationException::withMessages(['token' => ['Invalid verification token.']]);
        }

        if ($token->isExpired()) {
            return $this->errorResponse('This verification link has expired.', 410);
        }

        if ($token->used_at) {
            return $this->successResponse(['verified' => true], 'This email has already been verified.');
        }

        $user = $token->user;
        if (!$user->email_verified_at) {
            $user->update(['email_verified_at' => now()]);
        }

        $token->update(['used_at' => now()]);

        return $this->successResponse(['verified' => true], 'Email verified successfully.');
    }

    public function resend(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user) {
            return $this->unauthorizedResponse('Unable to determine authenticated user.');
        }

        if ($user->email_verified_at) {
            return $this->successResponse(['sent' => false], 'Your email is already verified.');
        }

        $token = EmailVerificationToken::generateFor($user);
        Mail::to($user->email)->send(new VerifyEmail($user, $token->token));

        return $this->successResponse(['sent' => true], 'Verification email resent successfully.');
    }

    public function status(Request $request): JsonResponse
    {
        $user = $request->user();

        return $this->successResponse([
            'verified' => (bool) $user->email_verified_at,
            'email_verified_at' => optional($user->email_verified_at)->toDateTimeString(),
        ]);
    }
}
