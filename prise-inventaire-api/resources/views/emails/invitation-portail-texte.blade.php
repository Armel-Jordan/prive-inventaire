@if ($langue === 'en')
Hello {{ $nom }},

@if ($nouveauMotDePasse)
Here is a link to choose a new password for the {{ $entreprise }} customer portal.
@else
{{ $entreprise }} gives you access to its customer portal: order online, follow your orders and download your invoices.
@endif

Choose my password: {{ $lien }}

This link is valid for {{ $jours }} days and can be used only once.
@else
Bonjour {{ $nom }},

@if ($nouveauMotDePasse)
Voici un lien pour choisir un nouveau mot de passe sur le portail client de {{ $entreprise }}.
@else
{{ $entreprise }} vous ouvre son portail client : commandez en ligne, suivez vos commandes et téléchargez vos factures.
@endif

Choisir mon mot de passe : {{ $lien }}

Ce lien est valable {{ $jours }} jours et ne sert qu'une fois.
@endif
