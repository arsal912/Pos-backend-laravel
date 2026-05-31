<!doctype html>
<html>
<head><meta charset="utf-8"><title>Trial Ended</title></head>
<body style="font-family: Arial, sans-serif; color: #333; background: #f7f7f7; padding: 20px;">
    <table width="100%" cellpadding="0" cellspacing="0" style="max-width: 600px; margin: 0 auto; background: #ffffff; border-radius: 8px; overflow: hidden;">
        <tr>
            <td style="background: #6f42c1; color: #ffffff; padding: 24px; text-align: center;">
                <h1 style="margin: 0; font-size: 24px;">🎉 Your Trial Has Ended</h1>
            </td>
        </tr>
        <tr>
            <td style="padding: 24px;">
                <p>Hello {{ $store->name }},</p>

                <p>Thank you for trying our POS platform! Your free trial has ended and your store access has been suspended.</p>

                <p>Upgrade to a paid plan to continue using all the features you explored during your trial:</p>

                <ul style="line-height: 1.8;">
                    <li>Full POS sales management</li>
                    <li>Inventory and products</li>
                    <li>Customer management</li>
                    <li>Reports and analytics</li>
                    <li>Multi-branch support</li>
                </ul>

                <div style="text-align: center; margin: 28px 0;">
                    <a href="{{ $upgradeUrl }}"
                       style="background: #6f42c1; color: #fff; padding: 14px 32px; border-radius: 6px; text-decoration: none; font-weight: bold; font-size: 16px;">
                        Upgrade Now
                    </a>
                </div>

                <p style="font-size: 13px; color: #666;">Your store data is kept for 30 days. Upgrade any time to restore access instantly.</p>
                <p>Thanks,<br>The POS Team</p>
            </td>
        </tr>
    </table>
</body>
</html>
