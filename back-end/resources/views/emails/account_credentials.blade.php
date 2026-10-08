<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Your Airking Inventory account</title>
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
                                Your account is ready
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding: 16px 28px 8px;">
                            <p style="margin: 0; font-size: 14px; line-height: 1.5; color: #3f3f46;">
                                Hi {{ $user->first_name }}, an administrator has created an
                                account for you. Sign in with the details below.
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding: 16px 28px;">
                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background-color: #f4f4f5; border-radius: 6px;">
                                <tr>
                                    <td style="padding: 16px 20px;">
                                        <p style="margin: 0 0 4px; font-size: 12px; color: #71717a;">Email</p>
                                        <p style="margin: 0 0 16px; font-size: 14px; font-weight: 600; color: #18181b; font-family: ui-monospace, 'Cascadia Code', 'Segoe UI Mono', monospace;">{{ $user->email }}</p>

                                        <p style="margin: 0 0 4px; font-size: 12px; color: #71717a;">Username</p>
                                        <p style="margin: 0 0 16px; font-size: 14px; font-weight: 600; color: #18181b; font-family: ui-monospace, 'Cascadia Code', 'Segoe UI Mono', monospace;">{{ $user->username }}</p>

                                        <p style="margin: 0 0 4px; font-size: 12px; color: #71717a;">Temporary password</p>
                                        {{-- Intentionally escaped, not {!! !!}: this is untrusted-looking
                                             text rendered into HTML, and {{ }} is what keeps a password
                                             containing "&" or "<" from being interpreted as markup. The
                                             mail client still shows the user the literal character --
                                             HTML entity decoding happens on display, not before the user
                                             reads/copies it. --}}
                                        <p style="margin: 0; font-size: 18px; font-weight: 700; letter-spacing: 0.05em; color: #18181b; font-family: ui-monospace, 'Cascadia Code', 'Segoe UI Mono', monospace;">{{ $plainPassword }}</p>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding: 8px 28px;">
                            <p style="margin: 0; font-size: 14px; line-height: 1.5; color: #3f3f46;">
                                You will be asked to choose a new password the first time you
                                sign in. Until you do, the rest of the system stays locked.
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding: 16px 28px 32px;">
                            <p style="margin: 0; font-size: 13px; line-height: 1.5; color: #a1a1aa;">
                                If you were not expecting this email, contact your administrator.
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
