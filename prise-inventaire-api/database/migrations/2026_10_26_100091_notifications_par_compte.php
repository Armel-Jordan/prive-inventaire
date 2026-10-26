<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Notifications par compte (anomalie A4) : une notification peut viser un compte (`admin_user_id`, vide : toute
 * l'entreprise, comme avant) ; chaque compte a sa lecture et peut la masquer pour lui seul (`notification_lectures`).
 * La colonne `lu` reste : « lue par au moins un compte » (elle sert à ne pas répéter une alerte et au nettoyage).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('notifications', 'admin_user_id')) {
            Schema::table('notifications', function (Blueprint $table) {
                $table->unsignedBigInteger('admin_user_id')->nullable()->index();
            });
        }

        if (! Schema::hasTable('notification_lectures')) {
            Schema::create('notification_lectures', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->index();
                $table->foreignId('notification_id')->constrained('notifications')->cascadeOnDelete();
                $table->unsignedBigInteger('admin_user_id');
                $table->timestamp('lu_le')->nullable();
                // Masquée par ce compte (corbeille de la cloche) : les autres comptes la voient encore.
                $table->boolean('masquee')->default(false);
                $table->timestamps();

                $table->unique(['notification_id', 'admin_user_id'], 'notification_lectures_unique');
                $table->index(['tenant_id', 'admin_user_id'], 'notification_lectures_compte');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_lectures');
        if (Schema::hasColumn('notifications', 'admin_user_id')) {
            Schema::table('notifications', function (Blueprint $table) {
                $table->dropColumn('admin_user_id');
            });
        }
    }
};
