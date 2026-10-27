<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RespaldoPqrsd extends Model
{
    protected $table = 'respaldos_pqrsd';

    protected $fillable = [
        'tipo',
        'ruta',
        'checksum_sha256',
        'cifrado',
        'destino_externo',
        'checksum_remoto',
        'estado_transferencia',
        'tamano',
        'estado',
        'detalle',
        'usuario_id',
        'iniciada_en',
        'finalizada_en',
    ];

    protected function casts(): array
    {
        return [
            'cifrado' => 'boolean',
            'iniciada_en' => 'datetime',
            'finalizada_en' => 'datetime',
        ];
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_id');
    }
}
