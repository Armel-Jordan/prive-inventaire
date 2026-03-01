<?php

namespace App\Console\Commands;

use App\Models\InventaireScan;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class TestDeleteScan extends Command
{
    protected $signature = 'app:test-delete-scan {id}';
    protected $description = 'Teste la suppression logique d\'un scan';

    public function handle()
    {
        $id = $this->argument('id');

        try {
            $scan = InventaireScan::findOrFail($id);
            $this->info("Scan trouvé: ID={$scan->id} NUMERO={$scan->numero}");

            // Tenter le soft delete
            $this->info("Tentative de soft delete...");
            $result = $scan->delete();
            $this->info("Résultat delete(): " . ($result ? 'true' : 'false'));

            // Vérifier en brut Oracle
            $brut = DB::connection('oracle')
                ->selectOne("SELECT ID, DELETED_AT FROM INVENTAIRE_SCAN WHERE ID = ?", [$id]);

            if ($brut) {
                $this->info("Oracle brut: ID={$brut->id} DELETED_AT=" . ($brut->deleted_at ?? 'NULL'));
            } else {
                $this->warn("Scan non trouvé en Oracle (suppression physique?)");
            }

            // Tenter manuellement
            $this->info("\nTest manual UPDATE...");
            DB::connection('oracle')
                ->statement("UPDATE INVENTAIRE_SCAN SET DELETED_AT = SYSTIMESTAMP WHERE ID = ?", [$id]);
            
            $brut2 = DB::connection('oracle')
                ->selectOne("SELECT ID, DELETED_AT FROM INVENTAIRE_SCAN WHERE ID = ?", [$id]);
            
            if ($brut2) {
                $this->info("Après UPDATE manuel: DELETED_AT=" . ($brut2->deleted_at ?? 'NULL'));
            }

        } catch (\Exception $e) {
            $this->error("Erreur: " . $e->getMessage());
            $this->error($e->getTraceAsString());
        }
    }
}
