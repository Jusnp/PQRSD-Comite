<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SubcategoriaPqrsd extends Model
{
    protected $table = 'subcategorias_pqrsd';

    protected $fillable = [
        'categoria_pqrsd_id',
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

    public function categoria(): BelongsTo
    {
        return $this->belongsTo(CategoriaPqrsd::class, 'categoria_pqrsd_id');
    }

    public function motivosSugeridos(): HasMany
    {
        return $this->hasMany(MotivoPqrsd::class, 'subcategoria_sugerida_id');
    }
}
