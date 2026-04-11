<?php

namespace App\Console\Commands;

use App\Models\InventaireScan;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class TestScanStatus extends Command
{
    protected $signature = 'app:test-scan-status';

    protected $description = 'Vérifie les scans actifs et supprimés';

    public function handle()
    {
        $this->info('=== Scans ACTIFS (via Eloquent / SoftDeletes) ===');
        $actifs = InventaireScan::all();
        $this->info('Total: '.$actifs->count());
        foreach ($actifs as $s) {
            $this->line("  ID={$s->id} NUMERO={$s->numero} QTY={$s->quantite} DELETED_AT={$s->deleted_at}");
        }

        $this->info("\n=== TOUS les scans y compris supprimés (withTrashed) ===");
        $tous = InventaireScan::withTrashed()->get();
        $this->info('Total: '.$tous->count());
        foreach ($tous as $s) {
            $this->line("  ID={$s->id} NUMERO={$s->numero} QTY={$s->quantite} DELETED_AT={$s->deleted_at}");
        }

        $this->info("\n=== Scans SUPPRIMÉS uniquement (onlyTrashed) ===");
        $supprimes = InventaireScan::onlyTrashed()->get();
        $this->info('Total: '.$supprimes->count());
        foreach ($supprimes as $s) {
            $this->line("  ID={$s->id} NUMERO={$s->numero} QTY={$s->quantite} DELETED_AT={$s->deleted_at}");
        }

        $this->info("\n=== Requête brute Oracle ===");
        $brut = DB::connection('oracle')
            ->select('SELECT ID, NUMERO, QUANTITE, DELETED_AT FROM INVENTAIRE_SCAN ORDER BY ID DESC FETCH FIRST 10 ROWS ONLY');
        foreach ($brut as $row) {
            $this->line("  ID={$row->id} NUMERO={$row->numero} QTY={$row->quantite} DELETED_AT=".($row->deleted_at ?? 'NULL'));
        }
    }
}
