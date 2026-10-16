# Espace client

Le site des clients des entreprises : ils y commandent à leurs prix, suivent leurs commandes, retrouvent leurs factures et gèrent leur profil. Il est distinct du back-office (`prise-inventaire-web`) et utilise la même API.

L'entreprise se désigne par son code :
- dans le lien qu'elle donne à ses clients (`/<code>`) ;
- dans le lien d'invitation (`/activation?entreprise=<code>&jeton=…`) ;
- sinon le client le saisit.

Fonctionnement complet : [docs/flows/FLOW-ESPACE-CLIENT.md](../docs/flows/FLOW-ESPACE-CLIENT.md).

## Développement

```bash
npm ci
VITE_API_URL=http://127.0.0.1:8000/api npm run dev   # http://localhost:5175
```

Le back-office se lance avec `VITE_ESPACE_CLIENT_URL=http://localhost:5175` pour que son écran Ventes → Espace client et les liens des courriels pointent vers ce site.

## Build et déploiement

- `npm run build` : en production, `VITE_API_URL=/api` (`.env.production`), même origine.
- Déploiement par `.github/workflows/client-cd.yml` sur le droplet, **port 8081** (nginx : `deploy/nginx-espace-client.conf`).
