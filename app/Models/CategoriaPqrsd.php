<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CategoriaPqrsd extends Model
{
    protected $table = 'categorias_pqrsd';

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

    public function subcategorias(): HasMany
    {
        return $this->hasMany(SubcategoriaPqrsd::class, 'categoria_pqrsd_id')->orderBy('nombre');
    }
}
