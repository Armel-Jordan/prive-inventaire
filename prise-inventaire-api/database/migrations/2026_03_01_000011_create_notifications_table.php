<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->string('type', 50); // transfert_complete, alerte_stock, approbation_requise, etc.
            $table->string('titre', 200);
            $table->text('message');
            $table->string('destinataire', 100)->nullable(); // null = tous
            $table->boolean('lu')->default(false);
            $table->json('data')->nullable(); // données supplémentaires
            $table->string('lien', 255)->nullable(); // lien vers la page concernée
            $table->timestamps();

            $table->index('destinataire');
            $table->index('lu');
            $table->index('type');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
