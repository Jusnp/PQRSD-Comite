<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DescargaAdjuntoPqrsd extends Model
{
    protected $table = 'descargas_adjunto_pqrsd';

    protected $fillable = [
        'adjunto_pqrsd_id',
        'pqrsd_id',
        'usuario_id',
        'ip',
        'user_agent',
    ];

    public function adjunto(): BelongsTo
    {
        return $this->belongsTo(AdjuntoPqrsd::class, 'adjunto_pqrsd_id');
    }

    public function pqrsd(): BelongsTo
    {
        return $this->belongsTo(Pqrsd::class, 'pqrsd_id');
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_id');
    }
}
