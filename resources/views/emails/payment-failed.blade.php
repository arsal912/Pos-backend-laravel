<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title>Payment Failed</title>
</head>
<body style="font-family: Arial, sans-serif; color: #333; background: #f7f7f7; padding: 20px;">
    <table width="100%" cellpadding="0" cellspacing="0" style="max-width: 600px; margin: 0 auto; background: #ffffff; border-radius: 8px; overflow: hidden;">
        <tr>
            <td style="background: #dc3545; color: #ffffff; padding: 24px; text-align: center;">
                <h1 style="margin: 0; font-size: 24px;">⚠️ Payment Failed</h1>
            </td>
        </tr>
        <tr>
            <td style="padding: 24px;">
                <p>Hello,</p>
                <p>We were unable to process your payment for <strong>{{ $subscription->plan->name ?? 'your subscription' }}</strong>.</p>

                @if($reason)
                <p style="background: #fff3cd; border: 1px solid #ffc107; padding: 12px; border-radius: 6px;">
                    <strong>Reason:</strong> {{ $reason }}
                </p>
                @endif

                @if($gracePeriodEndsAt)
                <p>Your account will remain active until <strong>{{ $gracePeriodEndsAt->format('F j, Y') }}</strong>.
                   Please update your payment method before then to avoid service interruption.</p>
                @endif

                <div style="text-align: center; margin: 28px 0;">
                    <a href="{{ env('FRONTEND_URL', 'http://localhost:3000') }}/dashboard/billing"
                       style="background: #0d6efd; color: #fff; padding: 12px 28px; border-radius: 6px; text-decoration: none; font-weight: bold;">
                        Update Payment Method
                    </a>
                </div>

                <p>If you have any questions, please contact our support team.</p>
                <p>Thanks,<br>The POS Team</p>
            </td>
        </tr>
    </table>
</body>
</html>
