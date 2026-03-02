# Prise Inventaire - Guide d'Utilisation

## Table des matières

1. [Connexion](#connexion)
2. [Tableau de bord](#tableau-de-bord)
3. [Gestion des inventaires](#gestion-des-inventaires)
4. [Relocalisation](#relocalisation)
5. [Alertes et notifications](#alertes-et-notifications)
6. [Rapports](#rapports)
7. [Administration](#administration)

---

## Connexion

### Accès à l'application

1. Ouvrez votre navigateur et accédez à l'URL de l'application
2. Entrez votre **email** et **mot de passe**
3. Sélectionnez votre **entreprise** (tenant)
4. Cliquez sur **Se connecter**

### Première connexion

Lors de votre première connexion, un administrateur doit vous créer un compte. Contactez votre responsable pour obtenir vos identifiants.

### Déconnexion

Cliquez sur **Déconnexion** en bas du menu latéral pour vous déconnecter en toute sécurité.

---

## Tableau de bord

Le tableau de bord affiche une vue d'ensemble de votre activité :

### Indicateurs principaux

- **Scans** : Nombre total d'enregistrements d'inventaire
- **Produits** : Nombre de produits référencés
- **Secteurs** : Nombre de zones de stockage
- **Employés** : Nombre d'employés actifs

### Section Relocalisation

- **Mouvements** : Total des mouvements de stock
- **Arrivages** : Entrées de marchandises
- **Transferts** : Déplacements entre secteurs
- **Aujourd'hui** : Activité du jour

### Actions requises

Cette section s'affiche uniquement si des actions nécessitent votre attention :
- Alertes de stock bas
- Notifications non lues
- Approbations en attente

### Actualisation automatique

Le tableau de bord se rafraîchit automatiquement toutes les 30 secondes. Vous pouvez :
- Cliquer sur **Auto/Manuel** pour activer/désactiver le rafraîchissement
- Cliquer sur l'icône de rafraîchissement pour une mise à jour immédiate

---

## Gestion des inventaires

### Consulter les scans

1. Cliquez sur **Inventaires** dans le menu
2. Utilisez les filtres pour affiner la recherche :
   - **Date** : Période de saisie
   - **Secteur** : Zone spécifique
   - **Employé** : Personne ayant effectué le scan
   - **Produit** : Recherche par numéro ou nom

### Créer un scan

1. Cliquez sur **Nouveau scan**
2. Remplissez les champs :
   - **Numéro produit** : Code-barres ou référence
   - **Nom produit** : Désignation (optionnel)
   - **Quantité** : Nombre d'unités comptées
   - **Secteur** : Zone où se trouve le produit
   - **Employé** : Personne effectuant le comptage
3. Cliquez sur **Enregistrer**

### Modifier un scan

1. Trouvez le scan dans la liste
2. Cliquez sur l'icône **Modifier** (crayon)
3. Modifiez les informations
4. Cliquez sur **Enregistrer**

### Supprimer un scan

1. Trouvez le scan dans la liste
2. Cliquez sur l'icône **Supprimer** (corbeille)
3. Confirmez la suppression

### Exporter les données

1. Appliquez les filtres souhaités
2. Cliquez sur **Exporter CSV**
3. Le fichier sera téléchargé automatiquement

---

## Relocalisation

La relocalisation permet de suivre les mouvements de stock.

### Types de mouvements

| Type | Description |
|------|-------------|
| **Arrivage** | Entrée de marchandise (réception fournisseur) |
| **Sortie** | Sortie de marchandise (expédition, perte) |
| **Transfert** | Déplacement entre deux secteurs |

### Créer un mouvement

1. Cliquez sur **Relocalisation** dans le menu
2. Cliquez sur **Nouveau mouvement**
3. Sélectionnez le **type** de mouvement
4. Remplissez les informations :
   - **Produit** : Numéro et nom
   - **Quantité** : Nombre d'unités
   - **Secteur source** : D'où vient le produit (transfert/sortie)
   - **Secteur destination** : Où va le produit (arrivage/transfert)
   - **Employé** : Personne effectuant l'opération
   - **Notes** : Commentaires (optionnel)
5. Cliquez sur **Enregistrer**

### Scan en lot

Pour scanner plusieurs produits pour un même mouvement :

1. Cliquez sur **Scan en lot**
2. Scannez ou saisissez les produits un par un
3. Vérifiez la liste des produits
4. Cliquez sur **Valider le lot**

### Scanner un secteur par QR Code

Si vos secteurs ont des QR codes :

1. Cliquez sur l'icône QR à côté du champ secteur
2. Scannez le QR code du secteur
3. Le secteur est automatiquement rempli

---

## Alertes et notifications

### Alertes de stock

Les alertes vous préviennent quand un produit atteint un seuil critique.

**Configurer une alerte :**
1. Allez dans **Alertes Stock**
2. Cliquez sur **Nouvelle alerte**
3. Sélectionnez le produit
4. Définissez le **seuil minimum**
5. Enregistrez

**Consulter les alertes :**
- Les produits en alerte sont affichés en rouge
- Le compteur d'alertes apparaît sur le tableau de bord

### Notifications

Les notifications vous informent des événements importants :
- Transferts terminés
- Approbations requises
- Alertes de stock déclenchées

**Gérer les notifications :**
1. Cliquez sur l'icône cloche dans la barre supérieure
2. Cliquez sur une notification pour voir les détails
3. Utilisez **Tout marquer lu** pour effacer les indicateurs

---

## Rapports

### Rapport par secteur

Visualisez les mouvements entrants et sortants par secteur :

1. Allez dans **Rapports**
2. Sélectionnez l'onglet **Par Secteur**
3. Choisissez le mois et l'année
4. Consultez le graphique et le tableau

### Rapport par employé

Analysez l'activité de chaque employé :

1. Sélectionnez l'onglet **Par Employé**
2. Visualisez le nombre de mouvements et scans par personne

### Évolution annuelle

Suivez les tendances sur l'année :

1. Sélectionnez l'onglet **Évolution Annuelle**
2. Consultez le graphique des mouvements et scans par mois

### Top produits

Identifiez les produits les plus actifs :

1. Sélectionnez l'onglet **Top Produits**
2. Consultez le classement des produits par nombre de mouvements

### Exporter un rapport

1. Configurez les filtres souhaités
2. Cliquez sur **Exporter CSV**

---

## Administration

### Gestion des utilisateurs

*(Réservé aux administrateurs)*

1. Allez dans **Employés** ou contactez votre administrateur
2. Créez, modifiez ou désactivez des comptes utilisateurs
3. Attribuez les rôles appropriés

### Gestion des secteurs

1. Allez dans **Secteurs**
2. Ajoutez de nouveaux secteurs avec code et nom
3. Générez des QR codes pour faciliter le scan

### Gestion des produits

1. Allez dans **Produits**
2. Consultez ou modifiez les informations produits
3. Les produits sont généralement créés automatiquement lors des scans

---

## Raccourcis clavier

| Raccourci | Action |
|-----------|--------|
| `Ctrl + F` | Rechercher |
| `Escape` | Fermer une fenêtre modale |
| `Enter` | Valider un formulaire |

---

## Résolution de problèmes

### Je ne peux pas me connecter

- Vérifiez que votre email et mot de passe sont corrects
- Vérifiez que vous avez sélectionné la bonne entreprise
- Contactez votre administrateur si le problème persiste

### Les données ne s'affichent pas

- Vérifiez votre connexion internet
- Rafraîchissez la page (F5)
- Videz le cache du navigateur

### Je n'ai pas accès à certaines fonctionnalités

- Votre rôle utilisateur peut limiter vos accès
- Contactez votre administrateur pour modifier vos permissions

---

## Support

Pour toute question ou problème :
- Consultez cette documentation
- Contactez votre administrateur système
- Email support : support@prise-inventaire.com

---

*Document mis à jour le 1er mars 2026*
