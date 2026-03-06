<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reception_arrivages_ligne', function (Blueprint $table) {
            $table->id();
            $table->foreignId('com_four_ligne_id')->constrained('com_four_ligne')->onDelete('restrict');
            $table->date('date_reception');
            $table->integer('quantite_recue');
            $table->foreignId('secteur_id')->nullable()->constrained('secteurs')->onDelete('set null');
            $table->string('lot_numero', 50)->nullable();
            $table->date('date_peremption')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('received_by')->constrained('admin_users')->onDelete('restrict');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reception_arrivages_ligne');
    }
};
