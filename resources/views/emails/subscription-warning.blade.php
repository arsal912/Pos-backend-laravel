<!doctype html>
<html>
<head><meta charset="utf-8"><title>Subscription Expiry Warning</title></head>
<body style="font-family: Arial, sans-serif; color: #333; background: #f7f7f7; padding: 20px;">
    <table width="100%" cellpadding="0" cellspacing="0" style="max-width: 600px; margin: 0 auto; background: #ffffff; border-radius: 8px; overflow: hidden;">
        <tr>
            <td style="background: {{ $daysLeft <= 1 ? '#dc3545' : '#f59e0b' }}; color: #ffffff; padding: 24px; text-align: center;">
                <h1 style="margin: 0; font-size: 24px;">
                    {{ $daysLeft <= 1 ? '🚨 Final Warning' : '⏰ Subscription Expiring Soon' }}
                </h1>
            </td>
        </tr>
        <tr>
            <td style="padding: 24px;">
                <p>Hello,</p>

                @if($daysLeft <= 1)
                    <p>Your <strong>{{ $subscription->plan->name ?? 'subscription' }}</strong> expires <strong>tomorrow</strong>.
                    After expiry, you will lose access to all POS features.</p>
                @else
                    <p>Your <strong>{{ $subscription->plan->name ?? 'subscription' }}</strong> subscription expires in
                    <strong>{{ $daysLeft }} days</strong> on {{ $subscription->ends_at?->format('F j, Y') }}.</p>
                @endif

                <table width="100%" cellpadding="8" cellspacing="0" style="margin: 20px 0; border: 1px solid #e5e5e5;">
                    <tr style="background: #f2f2f2;">
                        <td><strong>Plan</strong></td>
                        <td>{{ $subscription->plan->name ?? '—' }}</td>
                    </tr>
                    <tr>
                        <td><strong>Amount</strong></td>
                        <td>{{ number_format($subscription->amount, 2) }} {{ $subscription->currency }}</td>
                    </tr>
                    <tr style="background: #f2f2f2;">
                        <td><strong>Expiry Date</strong></td>
                        <td>{{ $subscription->ends_at?->format('F j, Y') ?? '—' }}</td>
                    </tr>
                </table>

                <div style="text-align: center; margin: 28px 0;">
                    <a href="{{ $renewUrl }}"
                       style="background: #0d6efd; color: #fff; padding: 14px 32px; border-radius: 6px; text-decoration: none; font-weight: bold; font-size: 16px;">
                        Manage Billing
                    </a>
                </div>

                <p>If you have already renewed or believe this is an error, please ignore this email.</p>
                <p>Thanks,<br>The POS Team</p>
            </td>
        </tr>
    </table>
</body>
</html>
