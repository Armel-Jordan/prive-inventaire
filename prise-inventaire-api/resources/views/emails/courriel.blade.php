<!DOCTYPE html>
<html lang="{{ $langue }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $titre }}</title>
    <style>
        p { margin: 0 0 12px; }
        ul, ol { margin: 0 0 12px; padding-left: 24px; }
        a { color: #2563eb; }
    </style>
</head>
<body style="margin: 0; padding: 0; background-color: #f3f4f6;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color: #f3f4f6; padding: 24px 12px;">
        <tr>
            <td align="center">
                <table role="presentation" width="600" cellpadding="0" cellspacing="0" style="width: 100%; max-width: 600px; background-color: #ffffff; border-radius: 8px; font-family: Arial, Helvetica, sans-serif; font-size: 14px; line-height: 1.6; color: #1f2937;">
                    <tr>
                        <td style="padding: 28px 32px;">
                            {!! $corps !!}
                            @if($signature)
                                <div style="margin-top: 20px; color: #374151;">{!! $signature !!}</div>
                            @endif
                        </td>
                    </tr>
                    @if($entreprise['nom'] !== '' || count($entreprise['details']))
                        <tr>
                            <td style="padding: 16px 32px; border-top: 1px solid #e5e7eb; font-size: 12px; line-height: 1.5; color: #6b7280;">
                                <strong style="color: #374151;">{{ $entreprise['nom'] }}</strong>
                                @foreach($entreprise['details'] as $detail)
                                    <br>{{ $detail }}
                                @endforeach
                            </td>
                        </tr>
                    @endif
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
