@if ($langue === 'en')
Hello {{ $nom }},

We received a request to reset your password for {{ $entreprise }}.

Choose a new password: {{ $lien }}

This link is valid for {{ $minutes }} minutes and can be used only once.
If you did not make this request, ignore this email: your password stays the same.
@else
Bonjour {{ $nom }},

Nous avons reçu une demande de réinitialisation de votre mot de passe pour {{ $entreprise }}.

Choisir un nouveau mot de passe : {{ $lien }}

Ce lien est valable {{ $minutes }} minutes et ne sert qu'une fois.
Si vous n'êtes pas à l'origine de cette demande, ignorez ce courriel : votre mot de passe reste inchangé.
@endif
