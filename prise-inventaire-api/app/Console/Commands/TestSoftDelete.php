<?php

namespace App\Console\Commands;

use App\Models\InventaireScan;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class TestSoftDelete extends Command
{
    protected $signature = 'app:test-soft-delete {id}';
    protected $description = 'Teste la suppression logique custom';

    public function handle()
    {
        $id = $this->argument('id');

        try {
            $scan = InventaireScan::findOrFail($id);
            $this->info("Scan trouvé: ID={$scan->id} NUMERO={$scan->numero}");

            // Avant suppression - check DELETED_AT
            $brut = DB::connection('oracle')
                ->selectOne("SELECT ID, DELETED_AT FROM INVENTAIRE_SCAN WHERE ID = ?", [$id]);
            $this->info("AVANT: DELETED_AT=" . ($brut->deleted_at ?? 'NULL'));

            // Utiliser softDelete() custom
            $this->info("Appel softDelete()...");
            $result = $scan->softDelete();
            $this->info("Résultat: " . ($result ? 'true' : 'false'));

            // Après suppression
            $brut2 = DB::connection('oracle')
                ->selectOne("SELECT ID, DELETED_AT FROM INVENTAIRE_SCAN WHERE ID = ?", [$id]);
            $this->info("APRÈS: DELETED_AT=" . ($brut2->deleted_at ?? 'NULL'));

            // Test du global scope
            $count = InventaireScan::count();
            $this->info("\nScans actifs (global scope): {$count}");

        } catch (\Exception $e) {
            $this->error("Erreur: " . $e->getMessage());
        }
    }
}
