# Prise Inventaire - Informations de Déploiement

## Architecture

| Composant | Hébergement | URL |
|-----------|-------------|-----|
| **Frontend** | AWS Amplify | `https://main.d3ph830gn7z155.amplifyapp.com` |
| **API Backend** | DigitalOcean | `http://143.110.210.158/api` |
| **Base de données** | DigitalOcean (MySQL) | `143.110.210.158:3306` |

---

## Serveur DigitalOcean

| Info | Valeur |
|------|--------|
| **IP** | `143.110.210.158` |
| **Utilisateur SSH** | `root` |
| **OS** | Ubuntu 24.04 LTS |
| **Plan** | $8/mois (1GB RAM, 35GB SSD) |

---

## API Backend (Laravel)

| Info | Valeur |
|------|--------|
| **URL** | `http://143.110.210.158/api` |
| **Chemin serveur** | `/var/www/prise-api/prise-inventaire-api` |
| **PHP Version** | 8.3 |

### Endpoints principaux
- Login: `POST /api/auth/login`
- Configurations: `GET /api/configurations`
- Secteurs: `GET /api/secteurs`
- Produits: `GET /api/produits`

---

## Base de Données MySQL

| Info | Valeur |
|------|--------|
| **Host** | `127.0.0.1` |
| **Port** | `3306` |
| **Database** | `prise_inventaire` |
| **Username** | `prise_user` |
| **Password** | `PriseInv2026!` |

### Connexion MySQL
```bash
mysql -u prise_user -p prise_inventaire
# Password: PriseInv2026!
```

---

## Utilisateur Admin

| Info | Valeur |
|------|--------|
| **Email** | `admin@prise.com` |
| **Password** | `Admin123` |
| **Role** | `admin` |

---

## Commandes Utiles

### Se connecter au serveur
```bash
ssh root@143.110.210.158
```

### Aller au projet
```bash
cd /var/www/prise-api/prise-inventaire-api
```

### Mettre à jour le code
```bash
cd /var/www/prise-api/prise-inventaire-api
git pull origin main
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan config:clear
php artisan cache:clear
chown -R www-data:www-data /var/www/prise-api
```

### Voir les logs Laravel
```bash
tail -f /var/www/prise-api/prise-inventaire-api/storage/logs/laravel.log
```

### Redémarrer les services
```bash
systemctl restart nginx
systemctl restart php8.3-fpm
```

---

## Frontend Web (AWS Amplify)

| Info | Valeur |
|------|--------|
| **URL** | `https://main.d3ph830gn7z155.amplifyapp.com` |
| **Hébergement** | AWS Amplify |
| **Repo GitHub** | `prise-inventaire-web` |

### Configurer la variable d'environnement
1. Aller sur [console.aws.amazon.com/amplify](https://console.aws.amazon.com/amplify)
2. Sélectionner l'app **prise-inventaire-web**
3. **Hosting** → **Environment variables**
4. Définir `VITE_API_URL` = `http://143.110.210.158/api`
5. **Redeploy** l'application

---

## Accès Base de Données (DBeaver)

| Info | Valeur |
|------|--------|
| **Host** | `143.110.210.158` |
| **Port** | `3306` |
| **Database** | `prise_inventaire` |
| **Username** | `prise_user` |
| **Password** | `PriseInv2026!` |

> Note: Dans DBeaver, activer `allowPublicKeyRetrieval = TRUE` dans Driver properties

---

## Notes

- Le repo GitHub est actuellement **public** (tu peux le remettre en privé)
- Pour un domaine personnalisé, configure le DNS et modifie `/etc/nginx/sites-available/prise-api`
- Pour HTTPS, installe Certbot : `apt install certbot python3-certbot-nginx && certbot --nginx`
