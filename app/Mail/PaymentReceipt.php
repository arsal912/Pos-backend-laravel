<?php

namespace App\Mail;

use App\Models\Payment;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class PaymentReceipt extends Mailable
{
    use SerializesModels;

    public Payment $payment;
    public $invoicePdf;

    public function __construct(Payment $payment, $invoicePdf)
    {
        $this->payment = $payment;
        $this->invoicePdf = $invoicePdf;
    }

    public function build()
    {
        return $this->subject('Your payment receipt')
            ->view('emails.payment-receipt')
            ->with([
                'payment' => $this->payment,
            ])
            ->attachData($this->invoicePdf->output(), $this->payment->invoice_number . '.pdf', [
                'mime' => 'application/pdf',
            ]);
    }
}
