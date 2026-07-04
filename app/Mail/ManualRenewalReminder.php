<?php

namespace App\Mail;

use App\Models\Subscription;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class ManualRenewalReminder extends Mailable
{
    use SerializesModels;

    public function __construct(
        public Subscription $subscription,
        public string $renewUrl,
        public string $gateway = 'jazzcash'
    ) {}

    public function build(): self
    {
        $daysLeft = now()->diffInDays($this->subscription->next_billing_at, false);
        $subject  = $daysLeft <= 1
            ? 'Final reminder — your subscription renews tomorrow'
            : "Your subscription renews in {$daysLeft} days";

        return $this->subject($subject)
            ->view('emails.manual-renewal-reminder')
            ->with([
                'subscription' => $this->subscription,
                'renewUrl'     => $this->renewUrl,
                'gateway'      => $this->gateway,
                'daysLeft'     => max(0, (int) $daysLeft),
            ]);
    }
}
