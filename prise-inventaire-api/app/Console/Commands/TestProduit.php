<?php

namespace App\Console\Commands;

use App\Models\ProduitMobile;
use Illuminate\Console\Command;

class TestProduit extends Command
{
    protected $signature = 'app:test-produit {numero}';

    protected $description = 'Test la recherche d\'un produit';

    public function handle()
    {
        $numero = $this->argument('numero');

        try {
            $this->info("Recherche du produit: {$numero}");

            $produit = ProduitMobile::where('NUMERO', $numero)->first();

            if ($produit) {
                $this->info('Produit trouvé:');
                $this->info(json_encode($produit->toArray(), JSON_PRETTY_PRINT));
            } else {
                $this->warn('Produit non trouvé');
            }

        } catch (\Exception $e) {
            $this->error('Erreur: '.$e->getMessage());
            $this->error('Trace: '.$e->getTraceAsString());
        }
    }
}
