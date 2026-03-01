<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class TestInventaireScan extends Command
{
    protected $signature = 'app:test-inventaire-scan';
    protected $description = 'Teste la structure de la table INVENTAIRE_SCAN';

    public function handle()
    {
        try {
            $this->info("Vérification de la structure de INVENTAIRE_SCAN...");
            
            // Récupérer les colonnes de la table
            $columns = DB::connection('oracle')
                ->select("SELECT COLUMN_NAME FROM USER_TAB_COLUMNS WHERE TABLE_NAME = 'INVENTAIRE_SCAN' ORDER BY COLUMN_ID");
            
            $this->info("Colonnes trouvées:");
            foreach ($columns as $column) {
                $this->line("  - " . $column->column_name);
            }
            
            // Vérifier si les colonnes d'audit existent
            $auditColumns = ['CREATED_AT', 'UPDATED_AT', 'DELETED_AT'];
            $missingColumns = [];
            
            $existingColumns = array_map(fn($col) => $col->column_name, $columns);
            
            foreach ($auditColumns as $auditCol) {
                if (!in_array($auditCol, $existingColumns)) {
                    $missingColumns[] = $auditCol;
                }
            }
            
            if (empty($missingColumns)) {
                $this->info("\n✅ Toutes les colonnes d'audit sont présentes");
            } else {
                $this->warn("\n⚠️  Colonnes d'audit manquantes: " . implode(', ', $missingColumns));
                $this->warn("Exécutez le script: database/sql/alter_inventaire_scan_add_audit.sql");
            }
            
        } catch (\Exception $e) {
            $this->error("Erreur: " . $e->getMessage());
        }
    }
}
