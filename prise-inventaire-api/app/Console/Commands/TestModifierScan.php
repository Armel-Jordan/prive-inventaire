<?php

namespace App\Console\Commands;

use App\Models\InventaireScan;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class TestModifierScan extends Command
{
    protected $signature = 'app:test-modifier-scan {id} {quantite}';
    protected $description = 'Teste la modification d\'un scan';

    public function handle()
    {
        $id = $this->argument('id');
        $quantite = $this->argument('quantite');
        
        try {
            $this->info("Recherche du scan ID: {$id}");
            
            $scan = InventaireScan::findOrFail($id);
            
            $this->info("Scan trouvé:");
            $this->info(json_encode($scan->toArray(), JSON_PRETTY_PRINT));
            
            $this->info("\nModification de la quantité à: {$quantite}");
            
            DB::connection('oracle')->beginTransaction();
            
            $scan->update([
                'QUANTITE' => $quantite,
            ]);
            
            DB::connection('oracle')->commit();
            
            $this->info("\n✅ Scan modifié avec succès");
            $this->info(json_encode($scan->fresh()->toArray(), JSON_PRETTY_PRINT));
            
        } catch (\Exception $e) {
            DB::connection('oracle')->rollBack();
            $this->error("Erreur: " . $e->getMessage());
            $this->error("Trace: " . $e->getTraceAsString());
        }
    }
}
