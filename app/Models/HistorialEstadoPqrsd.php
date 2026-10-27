<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HistorialEstadoPqrsd extends Model
{
    protected $table = 'historial_estados_pqrsd';

    protected $fillable = [
        'pqrsd_id',
        'usuario_id',
        'campo',
        'valor_anterior',
        'valor_nuevo',
        'motivo',
    ];

    public function pqrsd(): BelongsTo
    {
        return $this->belongsTo(Pqrsd::class, 'pqrsd_id');
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_id');
    }
}
