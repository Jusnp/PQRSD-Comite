<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MotivoPqrsd extends Model
{
    use HasFactory;

    protected $table = 'motivos_pqrsd';

    protected $fillable = [
        'tipo_pqrsd_id',
        'codigo',
        'nombre',
        'descripcion',
        'activo',
    ];

    protected function casts(): array
    {
        return [
            'activo' => 'boolean',
        ];
    }

    public function tipo(): BelongsTo
    {
        return $this->belongsTo(TipoPqrsd::class, 'tipo_pqrsd_id');
    }

    public function pqrsd(): HasMany
    {
        return $this->hasMany(Pqrsd::class, 'motivo_pqrsd_id');
    }
}
