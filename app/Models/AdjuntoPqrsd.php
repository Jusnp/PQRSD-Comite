<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdjuntoPqrsd extends Model
{
    protected $table = 'adjuntos_pqrsd';

    protected $fillable = [
        'pqrsd_id',
        'remision_interna_id',
        'subido_por',
        'nombre_original',
        'ruta',
        'mime',
        'tamano',
        'tipo',
    ];

    public function pqrsd(): BelongsTo
    {
        return $this->belongsTo(Pqrsd::class, 'pqrsd_id');
    }

    public function remision(): BelongsTo
    {
        return $this->belongsTo(RemisionInterna::class, 'remision_interna_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'subido_por');
    }
}
