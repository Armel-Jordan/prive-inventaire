<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Modules optionnels activés par entreprise (achats/ventes/finance).
 * Colonne JSON : liste des modules optionnels actifs. Les modules "core" sont
 * toujours actifs implicitement (voir config/modules.php).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('tenants', 'modules')) {
            Schema::table('tenants', function (Blueprint $table) {
                $table->json('modules')->nullable()->after('plan');
            });
        }

        // Initialise les tenants existants depuis le set par défaut de leur plan.
        $plans = config('modules.plans', []);
        foreach (DB::table('tenants')->get(['id', 'plan', 'modules']) as $tenant) {
            if ($tenant->modules !== null) {
                continue;
            }
            $default = $plans[$tenant->plan] ?? [];
            DB::table('tenants')->where('id', $tenant->id)->update([
                'modules' => json_encode(array_values($default)),
            ]);
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('tenants', 'modules')) {
            Schema::table('tenants', function (Blueprint $table) {
                $table->dropColumn('modules');
            });
        }
    }
};
