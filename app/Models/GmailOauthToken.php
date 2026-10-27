<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Crypt;

class GmailOauthToken extends Model
{
    protected $table = 'gmail_oauth_tokens';

    protected $fillable = [
        'cuenta_correo_id',
        'payload_encrypted',
        'scopes',
        'expires_at',
        'token_type',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
        ];
    }

    public function cuenta(): BelongsTo
    {
        return $this->belongsTo(CuentaCorreo::class, 'cuenta_correo_id');
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        $raw = Crypt::decryptString($this->payload_encrypted);
        $json = json_decode($raw, true);

        return is_array($json) ? $json : [];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function storeFor(CuentaCorreo $cuenta, array $payload, ?string $scopes = null): self
    {
        // Nunca loguear $payload
        return static::updateOrCreate(
            ['cuenta_correo_id' => $cuenta->id],
            [
                'payload_encrypted' => Crypt::encryptString(json_encode($payload)),
                'scopes' => $scopes,
                'expires_at' => isset($payload['expires_at'])
                    ? \Carbon\Carbon::createFromTimestamp((int) $payload['expires_at'], 'America/Bogota')
                    : (isset($payload['expires_in'])
                        ? now('America/Bogota')->addSeconds((int) $payload['expires_in'])
                        : null),
                'token_type' => $payload['token_type'] ?? 'Bearer',
            ]
        );
    }
}
