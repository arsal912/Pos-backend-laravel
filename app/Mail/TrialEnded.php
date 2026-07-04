<?php

namespace App\Mail;

use App\Models\Store;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class TrialEnded extends Mailable
{
    use SerializesModels;

    public function __construct(public Store $store) {}

    public function build(): self
    {
        return $this->subject('Your free trial has ended — upgrade to keep access')
            ->view('emails.trial-ended')
            ->with([
                'store'      => $this->store,
                'upgradeUrl' => rtrim(env('FRONTEND_URL', 'http://localhost:3000'), '/') . '/dashboard/billing',
            ]);
    }
}
