<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title>Invoice {{ $payment->invoice_number }}</title>
    <style>
        body { font-family: Arial, sans-serif; color: #333; }
        .container { max-width: 800px; margin: 0 auto; padding: 24px; }
        .header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; }
        .section { margin-bottom: 24px; }
        .section h2 { margin-bottom: 12px; }
        .table { width: 100%; border-collapse: collapse; }
        .table th, .table td { padding: 12px; border: 1px solid #ddd; }
        .table th { background: #f7f7f7; text-align: left; }
        .total { text-align: right; font-size: 16px; margin-top: 16px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div>
                <h1>Invoice</h1>
                <p>{{ $payment->invoice_number }}</p>
            </div>
            <div>
                <p>{{ $payment->paid_at->format('F j, Y') }}</p>
            </div>
        </div>

        <div class="section">
            <h2>Store</h2>
            <p>{{ $payment->subscription->store->name }}</p>
            <p>{{ $payment->subscription->store->address }}</p>
            <p>{{ $payment->subscription->store->city }}, {{ $payment->subscription->store->country }}</p>
        </div>

        <div class="section">
            <h2>Payment Details</h2>
            <table class="table">
                <tr>
                    <th>Description</th>
                    <th>Amount</th>
                </tr>
                <tr>
                    <td>Subscription Payment</td>
                    <td>{{ currency($payment->amount, $payment->currency) }}</td>
                </tr>
            </table>
        </div>

        <div class="total">
            <strong>Total: {{ currency($payment->amount, $payment->currency) }}</strong>
        </div>

        <div class="section">
            <p>Payment Method: {{ ucfirst($payment->gateway) }}</p>
            <p>Transaction ID: {{ $payment->gateway_payment_id }}</p>
        </div>
    </div>
</body>
</html>
