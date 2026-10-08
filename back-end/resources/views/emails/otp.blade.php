<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Your login code</title>
</head>
<body style="margin: 0; padding: 0; background-color: #f4f4f5; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background-color: #f4f4f5;">
        <tr>
            <td align="center" style="padding: 40px 16px;">
                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width: 480px; background-color: #ffffff; border-radius: 8px; box-shadow: 0 1px 3px rgba(0, 0, 0, 0.08);">
                    <tr>
                        <td style="padding: 32px 28px 8px;">
                            <p style="margin: 0; font-size: 18px; font-weight: 600; color: #18181b;">
                                Inventory System
                            </p>
                            <p style="margin: 8px 0 0; font-size: 14px; color: #71717a;">
                                Your one-time login code
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding: 16px 28px 8px;">
                            <p style="margin: 0; font-size: 14px; line-height: 1.5; color: #3f3f46;">
                                Use the code below to sign in as
                                <strong>{{ $user->email }}</strong>. It expires in {{ $ttlMinutes }} minutes.
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <td align="center" style="padding: 24px 28px;">
                            <p style="margin: 0; font-size: 32px; font-weight: 700; letter-spacing: 0.25em; color: #18181b; font-family: ui-monospace, 'Cascadia Code', 'Segoe UI Mono', monospace;">
                                {{ $otp }}
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding: 8px 28px 32px;">
                            <p style="margin: 0; font-size: 13px; line-height: 1.5; color: #a1a1aa;">
                                If you did not request this code, you can ignore this email.
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
