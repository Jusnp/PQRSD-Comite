<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CanalIngreso extends Model
{
    use HasFactory;

    protected $table = 'canales_ingreso';

    protected $fillable = [
        'codigo',
        'nombre',
        'descripcion',
        'requiere_digitalizacion',
        'activo',
    ];

    protected function casts(): array
    {
        return [
            'requiere_digitalizacion' => 'boolean',
            'activo' => 'boolean',
        ];
    }

    public function pqrsd(): HasMany
    {
        return $this->hasMany(Pqrsd::class, 'canal_ingreso_id');
    }
}
