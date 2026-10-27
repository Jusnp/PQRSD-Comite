<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('authentik_sub', 120)->nullable()->unique()->after('theme_preference');
            $table->uuid('authentik_user_pk')->nullable()->after('authentik_sub');
            $table->string('authentik_status', 40)->default('local')->after('authentik_user_pk');
            $table->uuid('authentik_invitation_pk')->nullable()->after('authentik_status');
            $table->text('authentik_invitation_url')->nullable()->after('authentik_invitation_pk');
            $table->timestamp('authentik_invitation_expires_at')->nullable()->after('authentik_invitation_url');
            $table->string('authentik_invitation_delivery', 40)->nullable()->after('authentik_invitation_expires_at');
            $table->text('authentik_last_error')->nullable()->after('authentik_invitation_delivery');
            $table->timestamp('last_login_at')->nullable()->after('authentik_last_error');
        });

        Schema::create('usuario_auditorias', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('accion', 80);
            $table->json('detalle')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('usuario_auditorias');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'authentik_sub',
                'authentik_user_pk',
                'authentik_status',
                'authentik_invitation_pk',
                'authentik_invitation_url',
                'authentik_invitation_expires_at',
                'authentik_invitation_delivery',
                'authentik_last_error',
                'last_login_at',
            ]);
        });
    }
};
