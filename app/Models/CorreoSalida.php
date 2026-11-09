<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CorreoSalida extends Model
{
    protected $table = 'correo_salidas';

    protected $fillable = [
        'pqrsd_id',
        'cuenta_correo_id',
        'tipo',
        'estado',
        'idempotency_key',
        'payload',
        'rfc_message_id',
        'gmail_message_id',
        'gmail_thread_id',
        'proveedor_invocado',
        'intentos',
        'ultimo_error',
        'enviado_en',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'proveedor_invocado' => 'boolean',
            'enviado_en' => 'datetime',
        ];
    }

    public function pqrsd(): BelongsTo
    {
        return $this->belongsTo(Pqrsd::class, 'pqrsd_id');
    }

    public function cuenta(): BelongsTo
    {
        return $this->belongsTo(CuentaCorreo::class, 'cuenta_correo_id');
    }
}
