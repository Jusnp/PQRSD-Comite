<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdjuntoCorreo extends Model
{
    protected $table = 'adjuntos_correo';

    protected $fillable = [
        'mensaje_correo_id',
        'nombre_original',
        'ruta',
        'mime',
        'tamano',
        'checksum',
    ];

    public function mensaje(): BelongsTo
    {
        return $this->belongsTo(MensajeCorreo::class, 'mensaje_correo_id');
    }
}
