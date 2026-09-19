<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $heading }}</title>
</head>
<body style="margin:0;padding:0;background:#05070d;color:#e2e8f0;font-family:Arial,Helvetica,sans-serif;">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background:#05070d;padding:28px 12px;">
    <tr><td align="center">
        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="max-width:600px;background:#0b1220;border:1px solid #1e293b;border-radius:14px;overflow:hidden;">
            <tr><td style="height:5px;background:{{ $accentColor }};font-size:0;line-height:0;">&nbsp;</td></tr>
            <tr><td style="padding:34px 38px 18px;text-align:center;">
                <div style="font-size:25px;line-height:30px;font-weight:800;letter-spacing:3px;color:#ffffff;">MATH<span style="color:{{ $accentColor }};">VERSE</span></div>
                <div style="margin-top:7px;font-size:10px;line-height:16px;letter-spacing:2px;text-transform:uppercase;color:#64748b;">{{ $eyebrow }}</div>
            </td></tr>
            <tr><td style="padding:10px 38px 36px;">
                <h1 style="margin:0 0 14px;font-size:25px;line-height:33px;color:#ffffff;text-align:center;">{{ $heading }}</h1>
                @if($recipientName !== '')
                    <p style="margin:0 0 10px;font-size:14px;line-height:22px;color:#cbd5e1;">Hello {{ $recipientName }},</p>
                @endif
                <p style="margin:0 0 22px;font-size:15px;line-height:24px;color:#94a3b8;">{{ $messageText }}</p>

                @if(count($details) > 0)
                    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="margin:0 0 24px;background:#070b13;border:1px solid #172033;border-radius:8px;">
                        @foreach($details as $detail)
                            <tr>
                                <td style="padding:13px 15px;{{ !$loop->last ? 'border-bottom:1px solid #172033;' : '' }}font-size:10px;line-height:17px;color:#64748b;text-transform:uppercase;letter-spacing:1px;">{{ $detail['label'] }}</td>
                                <td style="padding:13px 15px;{{ !$loop->last ? 'border-bottom:1px solid #172033;' : '' }}font-size:13px;line-height:19px;color:#e2e8f0;text-align:right;">{{ $detail['value'] }}</td>
                            </tr>
                        @endforeach
                    </table>
                @endif

                @if($actionLabel && $actionUrl)
                    <table role="presentation" cellspacing="0" cellpadding="0" border="0" align="center">
                        <tr><td bgcolor="{{ $accentColor }}" style="border-radius:7px;">
                            <a href="{{ $actionUrl }}" style="display:inline-block;padding:14px 28px;font-size:13px;line-height:18px;font-weight:800;letter-spacing:1px;text-transform:uppercase;color:#041014;text-decoration:none;">{{ $actionLabel }}</a>
                        </td></tr>
                    </table>
                    <p style="margin:24px 0 8px;font-size:11px;line-height:18px;color:#64748b;text-align:center;">If the button does not work, copy and paste this link:</p>
                    <p style="margin:0;padding:11px;background:#070b13;border:1px solid #172033;border-radius:6px;font-size:10px;line-height:16px;color:{{ $accentColor }};word-break:break-all;">{{ $actionUrl }}</p>
                @endif

                <div style="margin-top:24px;padding:14px 16px;border:1px solid #334155;background:#0a101c;border-radius:7px;font-size:12px;line-height:19px;color:#94a3b8;">{{ $securityNote }}</div>
            </td></tr>
            <tr><td style="padding:18px 30px;border-top:1px solid #172033;background:#080d17;text-align:center;font-size:10px;line-height:16px;color:#475569;">This automated notification was sent by Math MetaVerse. Please do not reply.</td></tr>
        </table>
    </td></tr>
</table>
</body>
</html>
