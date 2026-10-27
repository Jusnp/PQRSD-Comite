<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotificacionPqrsd extends Model
{
    protected $table = 'notificaciones_pqrsd';

    protected $fillable = [
        'pqrsd_id',
        'user_id',
        'tipo',
        'canal',
        'destinatario',
        'asunto',
        'mensaje',
        'estado',
        'error',
        'umbral_clave',
        'metadatos',
        'programada_para',
        'enviada_en',
    ];

    protected function casts(): array
    {
        return [
            'metadatos' => 'array',
            'programada_para' => 'datetime',
            'enviada_en' => 'datetime',
        ];
    }

    public function pqrsd(): BelongsTo
    {
        return $this->belongsTo(Pqrsd::class, 'pqrsd_id');
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
