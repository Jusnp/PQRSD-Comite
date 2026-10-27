<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SincronizacionCorreo extends Model
{
    protected $table = 'sincronizaciones_correo';

    protected $fillable = [
        'cuenta_correo_id',
        'resultado',
        'mensajes_nuevos',
        'mensajes_duplicados',
        'mensajes_error',
        'detalle',
        'iniciada_en',
        'finalizada_en',
    ];

    protected function casts(): array
    {
        return [
            'iniciada_en' => 'datetime',
            'finalizada_en' => 'datetime',
        ];
    }

    public function cuenta(): BelongsTo
    {
        return $this->belongsTo(CuentaCorreo::class, 'cuenta_correo_id');
    }
}
