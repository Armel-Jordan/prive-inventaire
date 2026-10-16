<!DOCTYPE html>
<html lang="{{ $langue }}">
<head>
    <meta charset="utf-8">
    <title>{{ $langue === 'en' ? 'Customer portal' : 'Portail client' }}</title>
</head>
<body style="margin:0;padding:24px;background:#f3f4f6;font-family:Arial,Helvetica,sans-serif;color:#1f2937;">
    <div style="max-width:560px;margin:0 auto;background:#ffffff;border-radius:8px;padding:32px;">
        @if ($langue === 'en')
            <p>Hello {{ $nom }},</p>
            @if ($nouveauMotDePasse)
                <p>Here is a link to choose a new password for the <strong>{{ $entreprise }}</strong> customer portal.</p>
            @else
                <p><strong>{{ $entreprise }}</strong> gives you access to its customer portal: order online, follow your orders and download your invoices.</p>
            @endif
            <p style="margin:28px 0;">
                <a href="{{ $lien }}" style="background:#7c3aed;color:#ffffff;text-decoration:none;padding:12px 20px;border-radius:6px;display:inline-block;">Choose my password</a>
            </p>
            <p>This link is valid for {{ $jours }} days and can be used only once.</p>
        @else
            <p>Bonjour {{ $nom }},</p>
            @if ($nouveauMotDePasse)
                <p>Voici un lien pour choisir un nouveau mot de passe sur le portail client de <strong>{{ $entreprise }}</strong>.</p>
            @else
                <p><strong>{{ $entreprise }}</strong> vous ouvre son portail client : commandez en ligne, suivez vos commandes et téléchargez vos factures.</p>
            @endif
            <p style="margin:28px 0;">
                <a href="{{ $lien }}" style="background:#7c3aed;color:#ffffff;text-decoration:none;padding:12px 20px;border-radius:6px;display:inline-block;">Choisir mon mot de passe</a>
            </p>
            <p>Ce lien est valable {{ $jours }} jours et ne sert qu'une fois.</p>
        @endif
        <p style="color:#9ca3af;font-size:12px;word-break:break-all;margin-top:24px;">{{ $lien }}</p>
    </div>
</body>
</html>
