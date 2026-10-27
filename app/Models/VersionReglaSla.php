<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VersionReglaSla extends Model
{
    protected $table = 'versiones_reglas_sla';

    protected $fillable = [
        'regla_sla_id',
        'version',
        'snapshot',
        'modificado_por',
        'motivo',
    ];

    protected function casts(): array
    {
        return [
            'snapshot' => 'array',
        ];
    }

    public function regla(): BelongsTo
    {
        return $this->belongsTo(ReglaSla::class, 'regla_sla_id');
    }

    public function modificador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'modificado_por');
    }
}
