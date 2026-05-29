<?php

namespace App\Services\PaymentGateways;

use App\Models\Payment;
use App\Models\PaymentEvent;
use App\Models\Subscription;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Barryvdh\DomPDF\Facade\Pdf;

abstract class BasePaymentGateway implements \App\Contracts\PaymentGatewayInterface
{
    protected array $credentials = [];
    protected string $gatewaySlug;
    protected string $gatewayName;

    public function __construct(array $credentials = [])
    {
        $this->credentials = $credentials;
    }

    protected function decryptCredentials(string $payload): array
    {
        try {
            return Crypt::decryptString($payload);
        } catch (\Throwable $e) {
            return json_decode($payload, true) ?: [];
        }
    }

    protected function encryptCredentials(array $credentials): string
    {
        return Crypt::encryptString(json_encode($credentials));
    }

    protected function createPaymentRecord(array $data): Payment
    {
        return Payment::updateOrCreate(
            [
                'gateway' => $data['gateway'],
                'gateway_payment_id' => $data['gateway_payment_id'],
            ],
            [
                'store_id' => $data['store_id'],
                'subscription_id' => $data['subscription_id'] ?? null,
                'amount' => $data['amount'],
                'currency' => $data['currency'] ?? 'USD',
                'status' => $data['status'],
                'paid_at' => $data['paid_at'] ?? now(),
                'invoice_number' => $data['invoice_number'] ?? null,
                'failure_reason' => $data['failure_reason'] ?? null,
                'refunded_at' => $data['refunded_at'] ?? null,
                'refund_amount' => $data['refund_amount'] ?? null,
                'gateway_response' => $data['gateway_response'] ?? null,
            ]
        );
    }

    protected function updateSubscription(Subscription $subscription, array $data): Subscription
    {
        $subscription->fill($data);
        $subscription->save();

        return $subscription;
    }

    protected function generateInvoiceNumber(): string
    {
        $year = now()->year;

        $counter = \DB::transaction(function () use ($year) {
            $record = \DB::table('invoice_counters')
                ->lockForUpdate()
                ->where('year', $year)
                ->first();

            if ($record) {
                $next = $record->counter + 1;
                \DB::table('invoice_counters')
                    ->where('year', $year)
                    ->update(['counter' => $next, 'updated_at' => now()]);

                return $next;
            }

            \DB::table('invoice_counters')->insert([
                'year' => $year,
                'counter' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return 1;
        });

        return sprintf('INV-%s-%06d', $year, $counter);
    }

    protected function logEvent(array $data): PaymentEvent
    {
        return PaymentEvent::create([
            'store_id' => $data['store_id'],
            'subscription_id' => $data['subscription_id'] ?? null,
            'payment_id' => $data['payment_id'] ?? null,
            'event_type' => $data['event_type'],
            'gateway' => $data['gateway'],
            'data' => $data['data'] ?? [],
        ]);
    }

    public function testConnection(): bool
    {
        return true;
    }

    protected function sendReceiptEmail(Payment $payment): void
    {
        $invoicePdf = $this->generateInvoicePdf($payment);

        Mail::to($payment->subscription->store->email)
            ->send(new \App\Mail\PaymentReceipt($payment, $invoicePdf));
    }

    protected function generateInvoicePdf(Payment $payment)
    {
        $html = view('invoices.default', ['payment' => $payment])->render();

        return Pdf::loadHTML($html);
    }
}
