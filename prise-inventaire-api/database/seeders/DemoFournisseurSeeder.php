<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DemoFournisseurSeeder extends Seeder
{
    public function run(): void
    {
        $tenant = DB::table('tenants')->first();
        $admin = DB::table('admin_users')->where('role', 'admin')->first();
        $tid = $tenant->id;
        $uid = $admin->id;

        // ── 2 Fournisseurs ────────────────────────────────────────────
        $f1 = DB::table('fournisseurs')->insertGetId([
            'tenant_id' => $tid,
            'code' => 'BRASS-NORD',
            'raison_sociale' => 'Brasserie du Nord',
            'email' => 'contact@brasserie-nord.com',
            'telephone' => '+33 1 23 45 67 89',
            'ville' => 'Paris',
            'pays' => 'France',
            'actif' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $f2 = DB::table('fournisseurs')->insertGetId([
            'tenant_id' => $tid,
            'code' => 'DIST-CARAIB',
            'raison_sociale' => 'Distrib Caraibes',
            'email' => 'info@distribcaraibes.com',
            'telephone' => '+596 5 96 00 11 22',
            'ville' => 'Fort-de-France',
            'pays' => 'Martinique',
            'actif' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // ── Secteur ───────────────────────────────────────────────────
        $sect = DB::table('secteurs')->where('tenant_id', $tid)->first();
        $sid = $sect
            ? $sect->id
            : DB::table('secteurs')->insertGetId([
                'tenant_id' => $tid,
                'code' => 'ST1',
                'nom' => 'Stockage Principal',
                'created_at' => now(), 'updated_at' => now(),
            ]);

        // ── 2 Produits avec conditionnement ───────────────────────────
        $p1 = DB::table('produits')->insertGetId([
            'tenant_id' => $tid,
            'numero' => 'BIERE-33CL',
            'description' => 'Biere Blonde 33cl',
            'mesure' => 'UN',
            'unite_achat' => 'PACK',
            'qte_par_unite_achat' => 24,
            'type' => 'BOISSON',
            'secteur_id' => $sid,
            'prix_unitaire' => 1.20,
            'actif' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $p2 = DB::table('produits')->insertGetId([
            'tenant_id' => $tid,
            'numero' => 'RHUM-70CL',
            'description' => 'Rhum Agricole 70cl',
            'mesure' => 'UN',
            'unite_achat' => 'CARTON',
            'qte_par_unite_achat' => 12,
            'type' => 'SPIRITUEUX',
            'secteur_id' => $sid,
            'prix_unitaire' => 18.50,
            'actif' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // ── Helper ────────────────────────────────────────────────────
        $makeCmd = function (string $num, int $fid, string $statut, int $uid, int $p1, int $p2, int $q1, int $q2) {
            $eid = DB::table('com_four_entete')->insertGetId([
                'numero' => $num,
                'fournisseur_id' => $fid,
                'date_commande' => now()->subDays(rand(1, 20))->toDateString(),
                'date_livraison_prevue' => now()->addDays(7)->toDateString(),
                'statut' => $statut,
                'montant_total' => 0,
                'created_by' => $uid,
                'created_at' => now(), 'updated_at' => now(),
            ]);

            $lignes = [
                [$p1, $q1, 1.20,  'PACK',   24],
                [$p2, $q2, 18.50, 'CARTON', 12],
            ];
            $total = 0;
            foreach ($lignes as $l) {
                $montant = $l[1] * $l[2];
                $total += $montant;
                DB::table('com_four_ligne')->insert([
                    'com_four_entete_id' => $eid,
                    'produit_id' => $l[0],
                    'quantite_commandee' => $l[1],
                    'quantite_recue' => 0,
                    'unite_achat' => $l[3],
                    'qte_par_unite_achat' => $l[4],
                    'prix_unitaire' => $l[2],
                    'montant_ligne' => $montant,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            DB::table('com_four_entete')->where('id', $eid)->update(['montant_total' => $total]);

            return $eid;
        };

        // ── Commande 1 : BROUILLON ────────────────────────────────────
        $makeCmd('CF-2026-001', $f1, 'brouillon', $uid, $p1, $p2, 5, 3);

        // ── Commande 2 : ENVOYEE ──────────────────────────────────────
        $makeCmd('CF-2026-002', $f2, 'envoyee', $uid, $p1, $p2, 10, 2);

        // ── Commande 3 : PARTIELLE (moitie recue) ─────────────────────
        $eid3 = $makeCmd('CF-2026-003', $f1, 'partielle', $uid, $p1, $p2, 6, 4);
        $lignes = DB::table('com_four_ligne')->where('com_four_entete_id', $eid3)->get();
        foreach ($lignes as $l) {
            $recue = (int) floor($l->quantite_commandee / 2);
            DB::table('com_four_ligne')->where('id', $l->id)->update(['quantite_recue' => $recue]);
            DB::table('reception_arrivages_ligne')->insert([
                'com_four_ligne_id' => $l->id,
                'date_reception' => now()->subDays(3)->toDateString(),
                'quantite_recue' => $recue,
                'secteur_id' => $sid,
                'received_by' => $uid,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        // ── Commande 4 : COMPLETE (tout recu) ────────────────────────
        $eid4 = $makeCmd('CF-2026-004', $f2, 'complete', $uid, $p1, $p2, 8, 6);
        $lignes = DB::table('com_four_ligne')->where('com_four_entete_id', $eid4)->get();
        foreach ($lignes as $l) {
            DB::table('com_four_ligne')->where('id', $l->id)->update(['quantite_recue' => $l->quantite_commandee]);
            DB::table('reception_arrivages_ligne')->insert([
                'com_four_ligne_id' => $l->id,
                'date_reception' => now()->subDays(1)->toDateString(),
                'quantite_recue' => $l->quantite_commandee,
                'secteur_id' => $sid,
                'received_by' => $uid,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        // ── Commande 5 : ANNULEE ──────────────────────────────────────
        $makeCmd('CF-2026-005', $f1, 'annulee', $uid, $p1, $p2, 3, 2);

        $this->command->info('DONE - 2 fournisseurs, 2 produits, 5 commandes, receptions creees.');
    }
}
