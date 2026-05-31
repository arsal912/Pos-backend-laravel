<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title>Subscription Renewal Reminder</title>
</head>
<body style="font-family: Arial, sans-serif; color: #333; background: #f7f7f7; padding: 20px;">
    <table width="100%" cellpadding="0" cellspacing="0" style="max-width: 600px; margin: 0 auto; background: #ffffff; border-radius: 8px; overflow: hidden;">
        <tr>
            <td style="background: #f59e0b; color: #ffffff; padding: 24px; text-align: center;">
                <h1 style="margin: 0; font-size: 24px;">⏰ Renewal Reminder</h1>
            </td>
        </tr>
        <tr>
            <td style="padding: 24px;">
                <p>Hello,</p>

                @if($daysLeft <= 1)
                    <p>Your <strong>{{ $subscription->plan->name ?? 'subscription' }}</strong> subscription expires <strong>tomorrow</strong>.
                    This is your final reminder to renew.</p>
                @else
                    <p>Your <strong>{{ $subscription->plan->name ?? 'subscription' }}</strong> subscription expires in
                    <strong>{{ $daysLeft }} days</strong> ({{ $subscription->next_billing_at?->format('F j, Y') }}).</p>
                @endif

                <p>Since you are using {{ ucfirst($gateway) }}, renewal requires a manual payment.
                Click the button below to renew your subscription now:</p>

                <div style="text-align: center; margin: 28px 0;">
                    <a href="{{ $renewUrl }}"
                       style="background: #0d6efd; color: #fff; padding: 14px 32px; border-radius: 6px; text-decoration: none; font-weight: bold; font-size: 16px;">
                        Renew Subscription
                    </a>
                </div>

                <table width="100%" cellpadding="8" cellspacing="0" style="margin: 16px 0; border: 1px solid #e5e5e5;">
                    <tr style="background: #f2f2f2;">
                        <td><strong>Plan</strong></td>
                        <td>{{ $subscription->plan->name ?? '—' }}</td>
                    </tr>
                    <tr>
                        <td><strong>Amount</strong></td>
                        <td>{{ number_format($subscription->amount, 2) }} {{ $subscription->currency }}</td>
                    </tr>
                    <tr style="background: #f2f2f2;">
                        <td><strong>Expires</strong></td>
                        <td>{{ $subscription->next_billing_at?->format('F j, Y') ?? '—' }}</td>
                    </tr>
                </table>

                @if($daysLeft <= 0)
                <p style="color: #dc3545;"><strong>Your subscription has already expired.</strong>
                Renew now to restore access to all features.</p>
                @endif

                <p>If you have any questions, please contact support.</p>
                <p>Thanks,<br>The POS Team</p>
            </td>
        </tr>
    </table>
</body>
</html>
