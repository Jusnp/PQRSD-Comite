<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AreaInstitucional extends Model
{
    protected $table = 'areas_institucionales';

    protected $fillable = [
        'codigo',
        'nombre',
        'orden',
        'activo',
    ];

    protected function casts(): array
    {
        return [
            'activo' => 'boolean',
        ];
    }

    public function remisiones(): HasMany
    {
        return $this->hasMany(RemisionInterna::class, 'area_institucional_id');
    }
}
