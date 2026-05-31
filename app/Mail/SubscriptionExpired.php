<?php

namespace App\Mail;

use App\Models\Subscription;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class SubscriptionExpired extends Mailable
{
    use SerializesModels;

    public function __construct(public Subscription $subscription) {}

    public function build(): self
    {
        return $this->subject('Your subscription has expired')
            ->view('emails.subscription-expired')
            ->with([
                'subscription'   => $this->subscription,
                'reactivateUrl'  => rtrim(env('FRONTEND_URL', 'http://localhost:3000'), '/') . '/dashboard/billing?reactivate=true',
            ]);
    }
}
