<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class ResetPassword extends Mailable
{
    use Queueable, SerializesModels;

    public User $user;
    public string $resetUrl;

    public function __construct(User $user, string $token)
    {
        $this->user = $user;
        $this->resetUrl = rtrim(config('app.frontend_url', env('FRONTEND_URL', 'http://localhost:3000')), '/') . '/reset-password?token=' . $token;
    }

    public function build()
    {
        return $this->subject('Reset your password')
            ->view('emails.reset-password')
            ->with([
                'user' => $this->user,
                'resetUrl' => $this->resetUrl,
            ]);
    }
}
