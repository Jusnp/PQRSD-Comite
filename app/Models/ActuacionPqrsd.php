<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ActuacionPqrsd extends Model
{
    protected $table = 'actuaciones_pqrsd';

    protected $fillable = [
        'pqrsd_id',
        'usuario_id',
        'tipo',
        'titulo',
        'detalle',
        'datos_adicionales',
        'es_interna',
    ];

    protected function casts(): array
    {
        return [
            'datos_adicionales' => 'array',
            'es_interna' => 'boolean',
        ];
    }

    public function pqrsd(): BelongsTo
    {
        return $this->belongsTo(Pqrsd::class, 'pqrsd_id');
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_id');
    }
}
