<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Mail\ResetPassword;
use App\Models\PasswordResetToken;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

class PasswordResetController extends Controller
{
    use ApiResponse;

    public function forgot(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => 'required|email',
        ]);

        $user = User::where('email', $validated['email'])->first();

        if ($user) {
            $token = PasswordResetToken::generateFor($user);
            Mail::to($user->email)->send(new ResetPassword($user, $token->token));

            $response = ['sent' => true];
            if (config('mail.default') === 'log' || app()->environment('local')) {
                $response['reset_url'] = $token->getResetUrl();
            }

            return $this->successResponse($response, 'Password reset instructions have been sent.');
        }

        return $this->successResponse(['sent' => false], 'Password reset instructions have been sent.');
    }

    public function validateToken(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'token' => 'required|string',
        ]);

        $token = PasswordResetToken::where('token', $validated['token'])->first();

        if (!$token) {
            throw ValidationException::withMessages(['token' => ['Invalid password reset token.']]);
        }

        if ($token->isExpired()) {
            $token->delete();

            return $this->errorResponse('This password reset link has expired.', 410);
        }

        return $this->successResponse([
            'valid' => true,
            'email' => $token->email,
        ], 'Password reset token is valid.');
    }

    public function reset(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'token' => 'required|string',
            'email' => 'required|email',
            'password' => 'required|string|min:8|confirmed',
        ]);

        $token = PasswordResetToken::where('token', $validated['token'])->first();

        if (!$token || $token->email !== $validated['email']) {
            throw ValidationException::withMessages(['token' => ['Invalid password reset token or email.']]);
        }

        if ($token->isExpired()) {
            $token->delete();

            return $this->errorResponse('This password reset link has expired.', 410);
        }

        $user = User::where('email', $validated['email'])->first();

        if (!$user) {
            return $this->errorResponse('Unable to reset password for this account.', 404);
        }

        $user->update(['password' => $validated['password']]);
        $user->tokens()->delete();
        PasswordResetToken::where('email', $user->email)->delete();

        return $this->successResponse(['reset' => true], 'Your password has been reset successfully.');
    }
}
