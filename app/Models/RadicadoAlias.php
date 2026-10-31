<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RadicadoAlias extends Model
{
    protected $table = 'radicados_alias';

    protected $fillable = [
        'pqrsd_id',
        'radicado',
        'motivo',
    ];

    public function pqrsd(): BelongsTo
    {
        return $this->belongsTo(Pqrsd::class, 'pqrsd_id');
    }
}
