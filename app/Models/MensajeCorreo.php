<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MensajeCorreo extends Model
{
    protected $table = 'mensajes_correo';

    protected $fillable = [
        'cuenta_correo_id',
        'pqrsd_id',
        'message_id',
        'in_reply_to',
        'references_header',
        'thread_key',
        'remitente',
        'remitente_nombre',
        'destinatario',
        'destinatarios_cc',
        'destinatario_original',
        'asunto',
        'cuerpo_texto',
        'cuerpo_html',
        'raw_headers',
        'raw_eml_path',
        'fecha_mensaje',
        'fecha_importacion',
        'direccion',
        'clasificacion',
        'estado_procesamiento',
        'radicado_detectado',
        'motivo_clasificacion',
        'error_procesamiento',
        'intentos',
        'proximo_reintento',
        'revisado_por',
        'revisado_en',
        'metadatos',
    ];

    protected function casts(): array
    {
        return [
            'fecha_mensaje' => 'datetime',
            'fecha_importacion' => 'datetime',
            'proximo_reintento' => 'datetime',
            'revisado_en' => 'datetime',
            'metadatos' => 'array',
        ];
    }

    public function cuenta(): BelongsTo
    {
        return $this->belongsTo(CuentaCorreo::class, 'cuenta_correo_id');
    }

    public function pqrsd(): BelongsTo
    {
        return $this->belongsTo(Pqrsd::class, 'pqrsd_id');
    }

    public function adjuntos(): HasMany
    {
        return $this->hasMany(AdjuntoCorreo::class, 'mensaje_correo_id');
    }

    public function revisor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revisado_por');
    }

    public function scopePendientesRevision($query)
    {
        return $query->where('clasificacion', 'pendiente_revision')
            ->where('direccion', 'entrante');
    }
}
