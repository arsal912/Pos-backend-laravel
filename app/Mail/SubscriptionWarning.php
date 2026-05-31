<?php

namespace App\Mail;

use App\Models\Subscription;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class SubscriptionWarning extends Mailable
{
    use SerializesModels;

    public function __construct(
        public Subscription $subscription,
        public int $daysLeft
    ) {}

    public function build(): self
    {
        $subject = match (true) {
            $this->daysLeft <= 1 => 'Final warning — your subscription expires tomorrow',
            $this->daysLeft <= 3 => "Your subscription expires in {$this->daysLeft} days",
            default              => "Heads up — your subscription expires in {$this->daysLeft} days",
        };

        return $this->subject($subject)
            ->view('emails.subscription-warning')
            ->with([
                'subscription' => $this->subscription,
                'daysLeft'     => $this->daysLeft,
                'renewUrl'     => rtrim(env('FRONTEND_URL', 'http://localhost:3000'), '/') . '/dashboard/billing',
            ]);
    }
}
