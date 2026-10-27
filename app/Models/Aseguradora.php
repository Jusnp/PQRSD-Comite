<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Aseguradora extends Model
{
    use HasFactory;

    protected $fillable = [
        'codigo',
        'nombre',
        'nit',
        'tipo',
        'activo',
    ];

    protected function casts(): array
    {
        return [
            'activo' => 'boolean',
        ];
    }

    public function pqrsd(): HasMany
    {
        return $this->hasMany(Pqrsd::class);
    }
}
