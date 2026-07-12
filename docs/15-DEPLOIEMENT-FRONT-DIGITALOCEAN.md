# 15 — Déploiement du front sur DigitalOcean (hors AWS)

> Le front (React) et le site marketing étaient sur AWS Amplify. Suite à la perte
> de l'accès AWS, le front est désormais servi par le **même nginx que l'API**, sur
> le droplet DigitalOcean. Front + API en **même origine** → plus aucun CORS.

## Principe

- API Laravel : `/var/www/prise-api/prise-inventaire-api` (inchangé).
- Front (build Vite) : `/var/www/prise-web` (fichiers statiques).
- nginx route : `/api/*` → Laravel (php-fpm), tout le reste → SPA (`index.html`).
- `prise-inventaire-web/.env.production` fixe `VITE_API_URL=/api` (même origine).

## Mise en place initiale (une fois, sur le droplet, en SSH)

```bash
# 1. Dossier du front
sudo mkdir -p /var/www/prise-web
sudo chown -R www-data:www-data /var/www/prise-web

# 2. Config nginx (depuis le repo cloné sur le droplet)
sudo cp /var/www/prise-api/deploy/nginx-prise-inventaire.conf /etc/nginx/sites-available/prise-inventaire
sudo ln -sf /etc/nginx/sites-available/prise-inventaire /etc/nginx/sites-enabled/prise-inventaire
sudo rm -f /etc/nginx/sites-enabled/default   # si présent
sudo nginx -t && sudo systemctl reload nginx
```

> Vérifier dans le fichier : `server_name`, le socket `php8.3-fpm.sock`, et les chemins.

## Déploiement continu

Le workflow `.github/workflows/frontend-cd.yml` se déclenche à chaque push sur `main`
touchant `prise-inventaire-web/**` :
1. `npm ci` + `npm run build` (mode production → `/api`).
2. Copie du `dist/` sur le droplet (`/var/www/prise-web`) via SSH.
3. `nginx -t && systemctl reload nginx`.

Il réutilise les secrets GitHub existants : `DO_HOST`, `DO_USER`, `DO_SSH_KEY`.

Premier déploiement manuel possible via l'onglet Actions → « Frontend CD » →
« Run workflow ».

## CORS

Front et API étant en même origine, le CORS n'est plus sollicité par le navigateur.
Si un jour le front est servi depuis un autre domaine, définir dans le `.env` de l'API :

```
CORS_ALLOWED_ORIGINS=https://mon-domaine
```

## Site marketing

`prise-inventaire-marketing` était aussi sur Amplify. Même approche possible
(build + servir en statique via nginx sur un autre chemin/sous-domaine), ou
hébergement statique séparé. À traiter si nécessaire.

## HTTPS (recommandé)

Installer un certificat Let's Encrypt sur le droplet :

```bash
sudo apt install certbot python3-certbot-nginx
sudo certbot --nginx -d ton-domaine
```

(nécessite un nom de domaine pointant sur l'IP du droplet).
