<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::connection('tenant')->create('inventaire_scan', function (Blueprint $table) {
            $table->id();
            $table->string('numero', 50);
            $table->string('type', 50)->nullable();
            $table->decimal('quantite', 15, 4);
            $table->string('unite_mesure', 20)->default('UN');
            $table->string('employe', 50);
            $table->string('secteur', 10);
            $table->timestamp('date_saisie')->useCurrent();
            $table->string('scanneur', 50)->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->timestamps();

            $table->index('numero');
            $table->index('employe');
            $table->index('secteur');
            $table->index('date_saisie');
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('inventaire_scan');
    }
};
