<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ReglaSla extends Model
{
    use HasFactory;

    protected $table = 'reglas_sla';

    protected $fillable = [
        'codigo',
        'nombre',
        'descripcion',
        'nivel_riesgo',
        'cantidad',
        'unidad',
        'prioridad',
        'activo',
        'version',
        'vigente_desde',
        'vigente_hasta',
        'creado_por',
    ];

    protected function casts(): array
    {
        return [
            'activo' => 'boolean',
            'vigente_desde' => 'datetime',
            'vigente_hasta' => 'datetime',
        ];
    }

    public function versiones(): HasMany
    {
        return $this->hasMany(VersionReglaSla::class, 'regla_sla_id');
    }

    public function creador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creado_por');
    }

    public function toSnapshot(): array
    {
        return $this->only([
            'codigo',
            'nombre',
            'descripcion',
            'nivel_riesgo',
            'cantidad',
            'unidad',
            'prioridad',
            'activo',
            'version',
        ]);
    }
}
