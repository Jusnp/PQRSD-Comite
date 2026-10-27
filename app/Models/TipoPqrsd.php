<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TipoPqrsd extends Model
{
    use HasFactory;

    protected $table = 'tipos_pqrsd';

    protected $fillable = [
        'codigo',
        'nombre',
        'descripcion',
        'orden',
        'activo',
    ];

    protected function casts(): array
    {
        return [
            'activo' => 'boolean',
        ];
    }

    public function motivos(): HasMany
    {
        return $this->hasMany(MotivoPqrsd::class, 'tipo_pqrsd_id');
    }

    public function pqrsd(): HasMany
    {
        return $this->hasMany(Pqrsd::class, 'tipo_pqrsd_id');
    }
}
