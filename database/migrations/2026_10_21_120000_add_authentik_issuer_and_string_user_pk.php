<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('authentik_user_pk', 64)->nullable()->change();
            $table->string('authentik_issuer', 255)->nullable()->after('authentik_user_pk');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('authentik_issuer');
            $table->uuid('authentik_user_pk')->nullable()->change();
        });
    }
};
