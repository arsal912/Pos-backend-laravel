<?php

namespace App\Mail;

use App\Models\Subscription;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class PaymentFailed extends Mailable
{
    use SerializesModels;

    public function __construct(
        public Subscription $subscription,
        public ?string $reason = null
    ) {}

    public function build(): self
    {
        return $this->subject('Payment failed — action required')
            ->view('emails.payment-failed')
            ->with([
                'subscription' => $this->subscription,
                'reason' => $this->reason,
                'gracePeriodEndsAt' => $this->subscription->grace_period_ends_at,
            ]);
    }
}
