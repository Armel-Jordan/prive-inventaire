<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class TestOracle extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:test-oracle';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';



    /**
     * Execute the console command.
     */
    public function handle()
    {
        //
        $result = DB::select("SELECT * FROM EMPLOYE");
        foreach ($result as $r) {
            $this->info(json_encode($r));
        }
        $this->info("Test Oracle OK");
    }
}
