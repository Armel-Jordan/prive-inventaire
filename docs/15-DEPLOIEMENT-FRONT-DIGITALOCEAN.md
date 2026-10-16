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

`prise-inventaire-marketing` (site statique) est servi par le **même nginx sur le
port 8080** (2ᵉ `server` dans `deploy/nginx-prise-inventaire.conf`). Sur son propre
port pour éviter les pièges de sous-chemin (base Vite / liens absolus).

Mise en place (une fois, SSH) :
```bash
sudo mkdir -p /var/www/prise-marketing
sudo chown -R www-data:www-data /var/www/prise-marketing
sudo ufw allow 8080      # ouvrir le port
# (la config nginx contient déjà le server{} port 8080)
sudo nginx -t && sudo systemctl reload nginx
```

Déploiement continu : `.github/workflows/marketing-cd.yml` (build + copie du `dist`
sur le droplet + reload nginx), déclenché sur push touchant `prise-inventaire-marketing/**`.

Accès : `http://<IP-droplet>:8080`. Avec un domaine, préférer un sous-domaine
(`marketing.domaine`) via `server_name` + un `server{}` en port 80/443.

> Le **service worker** (`sw.js`) — qui bloquait les visiteurs sur d'anciennes
> versions et causait des bugs — a été **retiré** (plus d'enregistrement dans
> `index.html`, fichier supprimé).

## Espace client (site des clients)

`prise-inventaire-client` (le site où les clients des entreprises commandent et suivent leurs commandes) est servi par le **même nginx sur le port 8081**, avec `/api` transmis à Laravel (même origine, pas de CORS). Détail du fonctionnement : [FLOW-ESPACE-CLIENT.md](flows/FLOW-ESPACE-CLIENT.md).

Déploiement continu : `.github/workflows/client-cd.yml`, déclenché sur push touchant `prise-inventaire-client/**`. Le workflow :
- construit le site et copie le `dist` dans `/var/www/prise-client` ;
- installe ou met à jour le site nginx `deploy/nginx-espace-client.conf` (`/etc/nginx/sites-available/prise-espace-client`). Une configuration refusée par `nginx -t` est retirée, sans toucher au back-office ;
- ouvre le port 8081 si `ufw` est actif.

À faire à la main seulement si un pare-feu DigitalOcean (panneau) filtre les ports : y autoriser 8081.

Accès : `http://<IP-droplet>:8081`, et `http://<IP-droplet>:8081/<code-entreprise>` pour la page de connexion d'une entreprise. Avec un domaine, préférer un sous-domaine (`clients.domaine`), puis régler `ESPACE_CLIENT_URL` (API) et `VITE_ESPACE_CLIENT_URL` (build du back-office).

## HTTPS (recommandé)

Installer un certificat Let's Encrypt sur le droplet :

```bash
sudo apt install certbot python3-certbot-nginx
sudo certbot --nginx -d ton-domaine
```

(nécessite un nom de domaine pointant sur l'IP du droplet).
