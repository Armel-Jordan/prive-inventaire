<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class TestRawDelete extends Command
{
    protected $signature = 'app:test-raw-delete {id}';
    protected $description = 'Test raw Oracle delete';

    public function handle()
    {
        $id = $this->argument('id');
        $conn = DB::connection('oracle');

        // Check current state
        $before = $conn->selectOne("SELECT ID, DELETED_AT FROM INVENTAIRE_SCAN WHERE ID = ?", [$id]);
        $this->info("AVANT: ID={$before->id} DELETED_AT=" . ($before->deleted_at ?? 'NULL'));

        // Check if PDO autocommit is on
        $pdo = $conn->getPdo();
        $this->info("PDO autocommit: " . ($pdo->getAttribute(\PDO::ATTR_AUTOCOMMIT) ? 'ON' : 'OFF'));
        
        // Check transaction level
        $this->info("Transaction level: " . $conn->transactionLevel());

        // Try raw update via connection
        $this->info("\nTest 1: DB::connection->update()");
        $affected = $conn->update("UPDATE INVENTAIRE_SCAN SET DELETED_AT = SYSTIMESTAMP WHERE ID = ?", [$id]);
        $this->info("Affected rows: {$affected}");

        $after1 = $conn->selectOne("SELECT ID, DELETED_AT FROM INVENTAIRE_SCAN WHERE ID = ?", [$id]);
        $this->info("APRÈS update(): DELETED_AT=" . ($after1->deleted_at ?? 'NULL'));

        if ($after1->deleted_at === null) {
            $this->info("\nTest 2: Try with explicit beginTransaction + commit");
            $conn->beginTransaction();
            $affected2 = $conn->update("UPDATE INVENTAIRE_SCAN SET DELETED_AT = SYSTIMESTAMP WHERE ID = ?", [$id]);
            $this->info("Affected rows: {$affected2}");
            $conn->commit();

            $after2 = $conn->selectOne("SELECT ID, DELETED_AT FROM INVENTAIRE_SCAN WHERE ID = ?", [$id]);
            $this->info("APRÈS commit: DELETED_AT=" . ($after2->deleted_at ?? 'NULL'));
        }

        if (($after1->deleted_at ?? null) === null && ($after2->deleted_at ?? null) === null) {
            $this->info("\nTest 3: unprepared statement");
            $conn->unprepared("UPDATE INVENTAIRE_SCAN SET DELETED_AT = SYSTIMESTAMP WHERE ID = {$id}");
            
            $after3 = $conn->selectOne("SELECT ID, DELETED_AT FROM INVENTAIRE_SCAN WHERE ID = ?", [$id]);
            $this->info("APRÈS unprepared: DELETED_AT=" . ($after3->deleted_at ?? 'NULL'));
        }
    }
}
