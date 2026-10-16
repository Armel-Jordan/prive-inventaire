<!DOCTYPE html>
<html lang="{{ $langue }}">
<head>
    <meta charset="utf-8">
    <title>{{ $langue === 'en' ? 'Reset your password' : 'Réinitialisation du mot de passe' }}</title>
</head>
<body style="margin:0;padding:24px;background:#f3f4f6;font-family:Arial,Helvetica,sans-serif;color:#1f2937;">
    <div style="max-width:560px;margin:0 auto;background:#ffffff;border-radius:8px;padding:32px;">
        @if ($langue === 'en')
            <p>Hello {{ $nom }},</p>
            <p>We received a request to reset your password for <strong>{{ $entreprise }}</strong>.</p>
            <p style="margin:28px 0;">
                <a href="{{ $lien }}" style="background:#7c3aed;color:#ffffff;text-decoration:none;padding:12px 20px;border-radius:6px;display:inline-block;">Choose a new password</a>
            </p>
            <p>This link is valid for {{ $minutes }} minutes and can be used only once.</p>
            <p style="color:#6b7280;font-size:13px;">If you did not make this request, ignore this email: your password stays the same.</p>
        @else
            <p>Bonjour {{ $nom }},</p>
            <p>Nous avons reçu une demande de réinitialisation de votre mot de passe pour <strong>{{ $entreprise }}</strong>.</p>
            <p style="margin:28px 0;">
                <a href="{{ $lien }}" style="background:#7c3aed;color:#ffffff;text-decoration:none;padding:12px 20px;border-radius:6px;display:inline-block;">Choisir un nouveau mot de passe</a>
            </p>
            <p>Ce lien est valable {{ $minutes }} minutes et ne sert qu'une fois.</p>
            <p style="color:#6b7280;font-size:13px;">Si vous n'êtes pas à l'origine de cette demande, ignorez ce courriel : votre mot de passe reste inchangé.</p>
        @endif
        <p style="color:#9ca3af;font-size:12px;word-break:break-all;margin-top:24px;">{{ $lien }}</p>
    </div>
</body>
</html>
