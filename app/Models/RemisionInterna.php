<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RemisionInterna extends Model
{
    public const ESTADO_PREPARADA = 'preparada';

    public const ESTADO_REMITIDA_CANAL_EXTERNO = 'remitida_canal_externo';

    public const ESTADO_PENDIENTE_RESPUESTA = 'pendiente_respuesta';

    public const ESTADO_RESPUESTA_RECIBIDA = 'respuesta_recibida';

    public const ESTADO_FINALIZADA = 'finalizada';

    public const ESTADOS = [
        self::ESTADO_PREPARADA => 'Preparada',
        self::ESTADO_REMITIDA_CANAL_EXTERNO => 'Registrada como remitida por canal externo',
        self::ESTADO_PENDIENTE_RESPUESTA => 'Pendiente de respuesta',
        self::ESTADO_RESPUESTA_RECIBIDA => 'Respuesta recibida',
        self::ESTADO_FINALIZADA => 'Finalizada',
    ];

    public const MEDIOS_COMUNICACION = [
        'correo_institucional_manual' => 'Correo institucional (envío manual fuera de Laravel)',
        'llamada_telefonica' => 'Llamada telefónica',
        'reunion_presencial' => 'Reunión / contacto presencial',
        'mensajeria_interna' => 'Mensajería interna / chat institucional',
        'otro' => 'Otro canal externo',
    ];

    protected $table = 'remisiones_internas';

    protected $fillable = [
        'pqrsd_id',
        'area_institucional_id',
        'motivo',
        'informacion_solicitada',
        'responsable_seguimiento_id',
        'fecha_limite_interna',
        'observaciones',
        'estado',
        'medio_comunicacion',
        'evidencia_envio_detalle',
        'fecha_remision',
        'fecha_respuesta',
        'persona_responde',
        'contenido_respuesta',
        'observaciones_respuesta',
        'creada_por',
        'finalizada_en',
    ];

    protected function casts(): array
    {
        return [
            'fecha_limite_interna' => 'datetime',
            'fecha_remision' => 'datetime',
            'fecha_respuesta' => 'datetime',
            'finalizada_en' => 'datetime',
        ];
    }

    public function etiquetaEstado(): string
    {
        return self::ESTADOS[$this->estado] ?? str_replace('_', ' ', (string) $this->estado);
    }

    public function etiquetaMedio(): string
    {
        if (! $this->medio_comunicacion) {
            return '—';
        }

        return self::MEDIOS_COMUNICACION[$this->medio_comunicacion] ?? $this->medio_comunicacion;
    }

    public function pqrsd(): BelongsTo
    {
        return $this->belongsTo(Pqrsd::class, 'pqrsd_id');
    }

    public function area(): BelongsTo
    {
        return $this->belongsTo(AreaInstitucional::class, 'area_institucional_id');
    }

    public function responsableSeguimiento(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsable_seguimiento_id');
    }

    public function creador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creada_por');
    }

    public function adjuntos(): HasMany
    {
        return $this->hasMany(AdjuntoPqrsd::class, 'remision_interna_id');
    }
}
