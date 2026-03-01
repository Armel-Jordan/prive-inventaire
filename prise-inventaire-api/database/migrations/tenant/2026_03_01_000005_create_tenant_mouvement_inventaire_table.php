<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::connection('tenant')->create('mouvement_inventaire', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('scan_id');
            $table->enum('type_mouvement', ['ENTREE', 'SORTIE', 'CORRECTION']);
            $table->decimal('quantite_avant', 15, 4);
            $table->decimal('quantite_apres', 15, 4);
            $table->string('motif', 255)->nullable();
            $table->string('utilisateur', 50);
            $table->timestamp('date_mouvement')->useCurrent();
            $table->timestamps();

            $table->index('scan_id');
            $table->index('type_mouvement');
            $table->index('date_mouvement');

            $table->foreign('scan_id')->references('id')->on('inventaire_scan')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('mouvement_inventaire');
    }
};
