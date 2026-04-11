<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employes', function (Blueprint $table) {
            if (! Schema::hasColumn('employes', 'photo')) {
                $table->string('photo', 255)->nullable()->after('email');
            }
            if (! Schema::hasColumn('employes', 'sexe')) {
                $table->enum('sexe', ['M', 'F', 'autre'])->nullable()->after('photo');
            }
            if (! Schema::hasColumn('employes', 'date_naissance')) {
                $table->date('date_naissance')->nullable()->after('sexe');
            }
            if (! Schema::hasColumn('employes', 'telephone')) {
                $table->string('telephone', 20)->nullable()->after('date_naissance');
            }
            if (! Schema::hasColumn('employes', 'adresse')) {
                $table->string('adresse', 255)->nullable()->after('telephone');
            }
            if (! Schema::hasColumn('employes', 'ville')) {
                $table->string('ville', 100)->nullable()->after('adresse');
            }
            if (! Schema::hasColumn('employes', 'code_postal')) {
                $table->string('code_postal', 20)->nullable()->after('ville');
            }
            if (! Schema::hasColumn('employes', 'pays')) {
                $table->string('pays', 100)->nullable()->after('code_postal');
            }
            if (! Schema::hasColumn('employes', 'poste')) {
                $table->string('poste', 100)->nullable()->after('pays');
            }
            if (! Schema::hasColumn('employes', 'departement')) {
                $table->string('departement', 100)->nullable()->after('poste');
            }
            if (! Schema::hasColumn('employes', 'date_embauche')) {
                $table->date('date_embauche')->nullable()->after('departement');
            }
        });
    }

    public function down(): void
    {
        Schema::table('employes', function (Blueprint $table) {
            $columns = ['photo', 'sexe', 'date_naissance', 'telephone', 'adresse', 'ville', 'code_postal', 'pays', 'poste', 'departement', 'date_embauche'];
            foreach ($columns as $column) {
                if (Schema::hasColumn('employes', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
