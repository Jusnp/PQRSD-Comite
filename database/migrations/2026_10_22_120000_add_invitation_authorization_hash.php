<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('authentik_authorization_hash', 64)->nullable()->after('authentik_invitation_pk');
        });

        DB::table('users')
            ->select('id', 'authentik_invitation_pk', 'authentik_invitation_url')
            ->orderBy('id')
            ->each(function (object $row): void {
                $cambios = [];
                $pk = (string) ($row->authentik_invitation_pk ?? '');
                if ($pk !== '') {
                    $cambios['authentik_authorization_hash'] = hash('sha256', $pk);
                }

                if (is_string($row->authentik_invitation_url) && $row->authentik_invitation_url !== ''
                    && ! str_starts_with($row->authentik_invitation_url, 'eyJ')) {
                    $cambios['authentik_invitation_url'] = Crypt::encryptString($row->authentik_invitation_url);
                }

                if ($cambios !== []) {
                    DB::table('users')->where('id', $row->id)->update($cambios);
                }
            });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('authentik_authorization_hash');
        });
    }
};
