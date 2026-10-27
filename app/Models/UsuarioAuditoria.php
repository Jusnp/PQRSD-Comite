<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UsuarioAuditoria extends Model
{
    protected $table = 'usuario_auditorias';

    protected $fillable = [
        'user_id',
        'actor_id',
        'accion',
        'detalle',
    ];

    protected function casts(): array
    {
        return [
            'detalle' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
