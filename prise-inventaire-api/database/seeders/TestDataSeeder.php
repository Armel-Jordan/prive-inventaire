<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Carbon\Carbon;

class TestDataSeeder extends Seeder
{
    private $tenantId;
    private $secteurs = [];
    private $employes = [];
    private $produits = [];

    public function run(): void
    {
        $this->command->info('🚀 Création des données de test à grande échelle...');

        // Récupérer le tenant "Entreprise Demo" ou le créer
        $tenant = DB::table('tenants')->where('slug', 'entreprise-demo')->first();

        if (!$tenant) {
            $this->tenantId = DB::table('tenants')->insertGetId([
                'nom' => 'Entreprise Demo',
                'slug' => 'entreprise-demo',
                'db_name' => 'prise_demo',
                'actif' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->command->info('✅ Tenant "Entreprise Demo" créé');
        } else {
            $this->tenantId = $tenant->id;
            $this->command->info('✅ Tenant "Entreprise Demo" trouvé (ID: ' . $this->tenantId . ')');
        }

        // Créer les utilisateurs de test
        $this->createUsers();

        // Créer les secteurs
        $this->createSecteurs();

        // Créer les employés
        $this->createEmployes();

        // Créer les produits
        $this->createProduits();

        // Créer les scans d'inventaire
        $this->createScans();

        // Créer les mouvements de relocalisation
        $this->createMouvements();

        // Créer les transferts planifiés
        $this->createTransfertsPlanifies();

        // Créer les approbations
        $this->createApprobations();

        // Créer les notifications
        $this->createNotifications();

        $this->command->info('');
        $this->command->info('🎉 Données de test créées avec succès !');
        $this->command->info('');
        $this->command->info('📊 Résumé:');
        $this->command->info('   - Secteurs: ' . count($this->secteurs));
        $this->command->info('   - Employés: ' . count($this->employes));
        $this->command->info('   - Produits: ' . count($this->produits));
        $this->command->info('   - Scans: ~5000');
        $this->command->info('   - Mouvements: ~2000');
    }

    private function createUsers(): void
    {
        $users = [
            ['nom' => 'Admin Demo', 'email' => 'admin@demo.com', 'role' => 'admin'],
            ['nom' => 'Manager Demo', 'email' => 'manager@demo.com', 'role' => 'manager'],
            ['nom' => 'User Demo', 'email' => 'user@demo.com', 'role' => 'user'],
            ['nom' => 'Readonly Demo', 'email' => 'readonly@demo.com', 'role' => 'user'],
        ];

        foreach ($users as $user) {
            $exists = DB::table('admin_users')
                ->where('email', $user['email'])
                ->exists();

            if (!$exists) {
                DB::table('admin_users')->insert([
                    'tenant_id' => $this->tenantId,
                    'nom' => $user['nom'],
                    'email' => $user['email'],
                    'password' => Hash::make('password123'),
                    'role' => $user['role'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        $this->command->info('✅ 4 utilisateurs créés (mot de passe: password123)');
    }

    private function createSecteurs(): void
    {
        $secteursList = [
            ['code' => 'A1', 'nom' => 'Entrepôt Principal - Zone A'],
            ['code' => 'A2', 'nom' => 'Entrepôt Principal - Zone B'],
            ['code' => 'A3', 'nom' => 'Entrepôt Principal - Zone C'],
            ['code' => 'B1', 'nom' => 'Réserve Nord'],
            ['code' => 'B2', 'nom' => 'Réserve Sud'],
            ['code' => 'C1', 'nom' => 'Quai de réception'],
            ['code' => 'C2', 'nom' => 'Quai d\'expédition'],
            ['code' => 'D1', 'nom' => 'Stockage froid'],
            ['code' => 'D2', 'nom' => 'Stockage sec'],
            ['code' => 'E1', 'nom' => 'Zone picking'],
            ['code' => 'E2', 'nom' => 'Zone préparation'],
            ['code' => 'F1', 'nom' => 'Magasin - Rayon 1'],
            ['code' => 'F2', 'nom' => 'Magasin - Rayon 2'],
            ['code' => 'F3', 'nom' => 'Magasin - Rayon 3'],
            ['code' => 'G1', 'nom' => 'Stockage extérieur'],
        ];

        foreach ($secteursList as $secteur) {
            $exists = DB::table('secteurs')
                ->where('code', $secteur['code'])
                ->exists();

            if (!$exists) {
                DB::table('secteurs')->insert([
                    'code' => $secteur['code'],
                    'nom' => $secteur['nom'],
                    'actif' => true,
                    'qr_code' => 'QR-' . $secteur['code'] . '-' . uniqid(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
            $this->secteurs[] = $secteur['code'];
        }

        $this->command->info('✅ ' . count($this->secteurs) . ' secteurs créés');
    }

    private function createEmployes(): void
    {
        $prenoms = ['Jean', 'Marie', 'Pierre', 'Sophie', 'Thomas', 'Julie', 'Nicolas', 'Emma', 'Lucas', 'Léa', 'Hugo', 'Chloé', 'Louis', 'Manon', 'Gabriel', 'Camille', 'Arthur', 'Sarah', 'Raphaël', 'Inès'];
        $noms = ['Martin', 'Bernard', 'Dubois', 'Thomas', 'Robert', 'Richard', 'Petit', 'Durand', 'Leroy', 'Moreau', 'Simon', 'Laurent', 'Lefebvre', 'Michel', 'Garcia', 'David', 'Bertrand', 'Roux', 'Vincent', 'Fournier'];

        for ($i = 1; $i <= 25; $i++) {
            $prenom = $prenoms[array_rand($prenoms)];
            $nom = $noms[array_rand($noms)];
            $code = 'EMP' . str_pad($i, 3, '0', STR_PAD_LEFT);

            $exists = DB::table('employes')
                ->where('numero', $code)
                ->exists();

            if (!$exists) {
                DB::table('employes')->insert([
                    'numero' => $code,
                    'nom' => $nom,
                    'prenom' => $prenom,
                    'actif' => $i <= 22,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
            $this->employes[] = $code;
        }

        $this->command->info('✅ ' . count($this->employes) . ' employés créés');
    }

    private function createProduits(): void
    {
        $categories = [
            'ELEC' => ['Câble USB', 'Chargeur', 'Batterie', 'Écouteurs', 'Adaptateur', 'Souris', 'Clavier', 'Webcam', 'Hub USB', 'Disque dur'],
            'FOUR' => ['Stylo', 'Cahier', 'Classeur', 'Agrafeuse', 'Ciseaux', 'Colle', 'Ruban adhésif', 'Post-it', 'Enveloppes', 'Papier A4'],
            'MEUB' => ['Chaise', 'Bureau', 'Étagère', 'Armoire', 'Table', 'Lampe', 'Poubelle', 'Porte-manteau', 'Caisson', 'Tableau'],
            'ALIM' => ['Café', 'Thé', 'Sucre', 'Lait', 'Biscuits', 'Eau', 'Jus', 'Fruits secs', 'Chocolat', 'Céréales'],
            'HYGI' => ['Savon', 'Papier toilette', 'Essuie-mains', 'Désinfectant', 'Gants', 'Masques', 'Gel hydroalcoolique', 'Lingettes', 'Sacs poubelle', 'Éponges'],
            'OUTI' => ['Tournevis', 'Marteau', 'Pince', 'Clé', 'Perceuse', 'Niveau', 'Mètre', 'Cutter', 'Scie', 'Vis'],
            'EMBA' => ['Carton petit', 'Carton moyen', 'Carton grand', 'Film étirable', 'Papier bulle', 'Scotch', 'Palette', 'Étiquettes', 'Sangles', 'Coins'],
            'INFO' => ['Écran', 'Unité centrale', 'Imprimante', 'Scanner', 'Routeur', 'Switch', 'Câble réseau', 'Toner', 'RAM', 'SSD'],
        ];

        $produitId = 1;
        foreach ($categories as $prefix => $items) {
            foreach ($items as $item) {
                $numero = $prefix . str_pad($produitId, 5, '0', STR_PAD_LEFT);

                // Les produits sont créés via les scans, pas besoin de table produits séparée
                $this->produits[] = ['numero' => $numero, 'nom' => $item];
                $produitId++;
            }
        }

        $this->command->info('✅ ' . count($this->produits) . ' produits créés');
    }

    private function createScans(): void
    {
        $scans = [];
        $now = Carbon::now();

        // Créer des scans sur les 6 derniers mois
        for ($i = 0; $i < 5000; $i++) {
            $produit = $this->produits[array_rand($this->produits)];
            $secteur = $this->secteurs[array_rand($this->secteurs)];
            $employe = $this->employes[array_rand($this->employes)];
            $daysAgo = rand(0, 180);
            $date = $now->copy()->subDays($daysAgo)->setTime(rand(6, 20), rand(0, 59), rand(0, 59));

            $scans[] = [
                'numero' => $produit['numero'],
                'quantite' => rand(1, 500),
                'unite_mesure' => 'UN',
                'secteur' => $secteur,
                'employe' => $employe,
                'date_saisie' => $date,
                'created_at' => $date,
                'updated_at' => $date,
            ];

            // Insérer par lots de 500
            if (count($scans) >= 500) {
                DB::table('inventaire_scan')->insert($scans);
                $scans = [];
            }
        }

        // Insérer le reste
        if (count($scans) > 0) {
            DB::table('inventaire_scan')->insert($scans);
        }

        $this->command->info('✅ 5000 scans créés (sur 6 mois)');
    }

    private function createMouvements(): void
    {
        $mouvements = [];
        $now = Carbon::now();
        $types = ['arrivage', 'sortie', 'transfert'];

        for ($i = 0; $i < 2000; $i++) {
            $produit = $this->produits[array_rand($this->produits)];
            $type = $types[array_rand($types)];
            $employe = $this->employes[array_rand($this->employes)];
            $daysAgo = rand(0, 180);
            $date = $now->copy()->subDays($daysAgo)->setTime(rand(6, 20), rand(0, 59), rand(0, 59));

            $secteurSource = null;
            $secteurDest = null;

            if ($type === 'arrivage') {
                $secteurDest = $this->secteurs[array_rand($this->secteurs)];
            } elseif ($type === 'sortie') {
                $secteurSource = $this->secteurs[array_rand($this->secteurs)];
            } else {
                $secteurSource = $this->secteurs[array_rand($this->secteurs)];
                do {
                    $secteurDest = $this->secteurs[array_rand($this->secteurs)];
                } while ($secteurDest === $secteurSource);
            }

            $mouvements[] = [
                'type' => $type,
                'produit_numero' => $produit['numero'],
                'produit_nom' => $produit['nom'],
                'quantite' => rand(1, 100),
                'secteur_source' => $secteurSource,
                'secteur_destination' => $secteurDest,
                'employe' => $employe,
                'motif' => rand(0, 10) > 7 ? 'Note de test pour mouvement' : null,
                'date_mouvement' => $date,
                'created_at' => $date,
                'updated_at' => $date,
            ];

            if (count($mouvements) >= 500) {
                DB::table('mouvement_relocalisation')->insert($mouvements);
                $mouvements = [];
            }
        }

        if (count($mouvements) > 0) {
            DB::table('mouvement_relocalisation')->insert($mouvements);
        }

        $this->command->info('✅ 2000 mouvements créés (sur 6 mois)');
    }

    private function createAlertes(): void
    {
        $alertes = [];

        // Créer des alertes pour 20 produits aléatoires
        $produitsAlerte = array_rand($this->produits, 20);

        foreach ($produitsAlerte as $index) {
            $produit = $this->produits[$index];
            $seuilMin = rand(10, 50);
            $quantiteActuelle = rand(0, 60);

            $alertes[] = [
                'tenant_id' => $this->tenantId,
                'produit_numero' => $produit['numero'],
                'produit_nom' => $produit['nom'],
                'seuil_min' => $seuilMin,
                'quantite_actuelle' => $quantiteActuelle,
                'statut' => $quantiteActuelle < $seuilMin ? 'alerte' : 'normal',
                'actif' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        DB::table('alertes_stock')->insert($alertes);
        $this->command->info('✅ 20 alertes de stock créées');
    }

    private function createTransfertsPlanifies(): void
    {
        $transferts = [];
        $now = Carbon::now();
        $statuts = ['planifie', 'planifie', 'planifie', 'en_cours', 'termine', 'annule'];

        for ($i = 0; $i < 30; $i++) {
            $produit = $this->produits[array_rand($this->produits)];
            $employe = $this->employes[array_rand($this->employes)];
            $secteurSource = $this->secteurs[array_rand($this->secteurs)];
            do {
                $secteurDest = $this->secteurs[array_rand($this->secteurs)];
            } while ($secteurDest === $secteurSource);

            $datePrevue = $now->copy()->addDays(rand(-10, 14));
            $statut = $statuts[array_rand($statuts)];

            // Si date passée, statut terminé ou annulé
            if ($datePrevue->isPast()) {
                $statut = rand(0, 1) ? 'termine' : 'annule';
            }

            $transferts[] = [
                'type' => 'transfert',
                'produit_numero' => $produit['numero'],
                'produit_nom' => $produit['nom'],
                'quantite' => rand(10, 200),
                'secteur_source' => $secteurSource,
                'secteur_destination' => $secteurDest,
                'employe' => $employe,
                'date_planifiee' => $datePrevue,
                'statut' => $statut,
                'notes' => 'Transfert planifié #' . ($i + 1),
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        DB::table('transferts_planifies')->insert($transferts);
        $this->command->info('✅ 30 transferts planifiés créés');
    }

    private function createApprobations(): void
    {
        $approbations = [];
        $statuts = ['en_attente', 'en_attente', 'en_attente', 'approuve', 'rejete'];

        for ($i = 0; $i < 15; $i++) {
            $produit = $this->produits[array_rand($this->produits)];
            $employe = $this->employes[array_rand($this->employes)];
            $secteurSource = $this->secteurs[array_rand($this->secteurs)];
            $secteurDest = $this->secteurs[array_rand($this->secteurs)];
            $statut = $statuts[array_rand($statuts)];

            $quantite = rand(100, 500);
            $approbations[] = [
                'type_mouvement' => 'transfert',
                'produit_numero' => $produit['numero'],
                'produit_nom' => $produit['nom'],
                'secteur_source' => $secteurSource,
                'secteur_destination' => $secteurDest,
                'quantite' => $quantite,
                'motif' => 'Demande de transfert important',
                'demandeur' => $employe,
                'statut' => $statut,
                'approbateur' => $statut !== 'en_attente' ? 'Admin Demo' : null,
                'date_decision' => $statut !== 'en_attente' ? now() : null,
                'commentaire_approbateur' => $statut === 'rejete' ? 'Quantité trop importante' : null,
                'seuil_declenchement' => 100,
                'created_at' => now()->subDays(rand(0, 7)),
                'updated_at' => now(),
            ];
        }

        DB::table('approbations')->insert($approbations);
        $this->command->info('✅ 15 approbations créées');
    }

    private function createNotifications(): void
    {
        $notifications = [];
        $types = ['transfert_complete', 'approbation_requise', 'alerte_stock', 'planification_imminente'];
        $titres = [
            'transfert_complete' => 'Transfert terminé',
            'approbation_requise' => 'Approbation requise',
            'alerte_stock' => 'Alerte stock bas',
            'planification_imminente' => 'Transfert prévu demain',
        ];

        for ($i = 0; $i < 50; $i++) {
            $type = $types[array_rand($types)];
            $produit = $this->produits[array_rand($this->produits)];

            $notifications[] = [
                'type' => $type,
                'titre' => $titres[$type],
                'message' => 'Notification concernant le produit ' . $produit['nom'],
                'destinataire' => 'admin@demo.com',
                'lu' => rand(0, 1),
                'lien' => null,
                'created_at' => now()->subHours(rand(0, 168)),
                'updated_at' => now(),
            ];
        }

        DB::table('notifications')->insert($notifications);
        $this->command->info('✅ 50 notifications créées');
    }
}
