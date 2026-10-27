<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AsignacionPqrsd extends Model
{
    protected $table = 'asignaciones_pqrsd';

    protected $fillable = [
        'pqrsd_id',
        'asignado_a',
        'asignado_por',
        'motivo',
        'asignado_en',
        'finalizado_en',
        'activa',
    ];

    protected function casts(): array
    {
        return [
            'asignado_en' => 'datetime',
            'finalizado_en' => 'datetime',
            'activa' => 'boolean',
        ];
    }

    public function pqrsd(): BelongsTo
    {
        return $this->belongsTo(Pqrsd::class, 'pqrsd_id');
    }

    public function asignado(): BelongsTo
    {
        return $this->belongsTo(User::class, 'asignado_a');
    }

    public function asignador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'asignado_por');
    }
}
