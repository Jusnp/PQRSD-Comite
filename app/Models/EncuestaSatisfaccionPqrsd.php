<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EncuestaSatisfaccionPqrsd extends Model
{
    protected $table = 'encuestas_satisfaccion_pqrsd';

    protected $fillable = [
        'pqrsd_id',
        'puntuacion',
        'atendida',
        'comentario',
        'respondida_en',
    ];

    protected function casts(): array
    {
        return [
            'respondida_en' => 'datetime',
        ];
    }

    public function pqrsd(): BelongsTo
    {
        return $this->belongsTo(Pqrsd::class, 'pqrsd_id');
    }

    public function esBaja(): bool
    {
        return $this->puntuacion <= 2 || $this->atendida === 'no';
    }
}
