<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Your Password</title>
</head>
<body style="font-family: Arial, sans-serif; background: #f4f6fb; margin: 0; padding: 0;">
    <table width="100%" cellpadding="0" cellspacing="0">
        <tr>
            <td align="center" style="padding: 32px 16px;">
                <table width="600" cellpadding="0" cellspacing="0" style="background: #ffffff; border-radius: 16px; overflow: hidden; box-shadow: 0 20px 80px rgba(0, 0, 0, 0.08);">
                    <tr>
                        <td style="padding: 32px; text-align: center; background: #1f2937; color: #ffffff;">
                            <h1 style="margin: 0; font-size: 28px;">Reset Your Password</h1>
                            <p style="margin: 8px 0 0; color: #d1d5db;">We received a request to reset your password.</p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding: 32px; color: #111827;">
                            <p style="font-size: 16px; margin: 0 0 16px;">Hi {{ $user->name }},</p>
                            <p style="font-size: 16px; margin: 0 0 24px;">Click the button below to choose a new password for your account. If you did not request this, you can safely ignore this email.</p>
                            <p style="text-align: center; margin: 0 0 32px;">
                                <a href="{{ $resetUrl }}" style="display: inline-block; padding: 14px 28px; background: #2563eb; color: #ffffff; border-radius: 10px; text-decoration: none; font-weight: 600;">Reset password</a>
                            </p>
                            <p style="font-size: 14px; color: #6b7280; margin: 0 0 8px;">If the button above doesn't work, copy and paste this link into your browser:</p>
                            <p style="font-size: 13px; color: #6b7280; word-break: break-all;">{{ $resetUrl }}</p>
                            <p style="font-size: 14px; color: #6b7280; margin: 24px 0 0;">If you did not request a password reset, you can ignore this message.</p>
                        </td>
                    </tr>
                    <tr>
                        <td style="background: #f9fafb; padding: 24px; color: #6b7280; font-size: 13px; text-align: center;">
                            POS System • {{ config('app.name') }}
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
