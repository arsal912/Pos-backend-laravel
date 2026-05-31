<!doctype html>
<html>
<head><meta charset="utf-8"><title>Subscription Expired</title></head>
<body style="font-family: Arial, sans-serif; color: #333; background: #f7f7f7; padding: 20px;">
    <table width="100%" cellpadding="0" cellspacing="0" style="max-width: 600px; margin: 0 auto; background: #ffffff; border-radius: 8px; overflow: hidden;">
        <tr>
            <td style="background: #dc3545; color: #ffffff; padding: 24px; text-align: center;">
                <h1 style="margin: 0; font-size: 24px;">❌ Subscription Expired</h1>
            </td>
        </tr>
        <tr>
            <td style="padding: 24px;">
                <p>Hello,</p>

                <p>Your <strong>{{ $subscription->plan->name ?? 'subscription' }}</strong> has expired.
                Access to your POS dashboard has been suspended.</p>

                <p style="background: #fff3cd; border: 1px solid #ffc107; padding: 12px; border-radius: 6px;">
                    <strong>Your data is safe.</strong> We keep your store data for 30 days.
                    Reactivate now to restore full access immediately.
                </p>

                <div style="text-align: center; margin: 28px 0;">
                    <a href="{{ $reactivateUrl }}"
                       style="background: #198754; color: #fff; padding: 14px 32px; border-radius: 6px; text-decoration: none; font-weight: bold; font-size: 16px;">
                        Reactivate My Store
                    </a>
                </div>

                <p>If you no longer need the service, no action is required.</p>
                <p>Thanks,<br>The POS Team</p>
            </td>
        </tr>
    </table>
</body>
</html>
