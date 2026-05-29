<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title>Payment Receipt</title>
</head>
<body style="font-family: Arial, sans-serif; color: #333; background: #f7f7f7; padding: 20px;">
    <table width="100%" cellpadding="0" cellspacing="0" style="max-width: 600px; margin: 0 auto; background: #ffffff; border-radius: 8px; overflow: hidden;">
        <tr>
            <td style="background: #0d6efd; color: #ffffff; padding: 24px; text-align: center;">
                <h1 style="margin: 0; font-size: 24px;">Payment Receipt</h1>
            </td>
        </tr>
        <tr>
            <td style="padding: 24px;">
                <p>Hello,</p>
                <p>Thank you for your payment. Below are the details of your transaction.</p>
                <table width="100%" cellpadding="8" cellspacing="0" style="margin: 20px 0; border: 1px solid #e5e5e5;">
                    <tr style="background: #f2f2f2;">
                        <td><strong>Invoice</strong></td>
                        <td>{{ $payment->invoice_number }}</td>
                    </tr>
                    <tr>
                        <td><strong>Amount</strong></td>
                        <td>{{ currency($payment->amount, $payment->currency) }}</td>
                    </tr>
                    <tr style="background: #f2f2f2;">
                        <td><strong>Payment Method</strong></td>
                        <td>{{ ucfirst($payment->gateway) }}</td>
                    </tr>
                    <tr>
                        <td><strong>Status</strong></td>
                        <td>{{ ucfirst($payment->status) }}</td>
                    </tr>
                    <tr style="background: #f2f2f2;">
                        <td><strong>Paid At</strong></td>
                        <td>{{ $payment->paid_at->toDayDateTimeString() }}</td>
                    </tr>
                </table>
                <p>If you need anything else, please contact support.</p>
                <p>Thanks,<br>The POS Team</p>
            </td>
        </tr>
    </table>
</body>
</html>
