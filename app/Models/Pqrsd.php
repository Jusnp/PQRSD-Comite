<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Pqrsd extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'pqrsd';

    protected $fillable = [
        'radicado',
        'consulta_token_hash',
        'fecha_recepcion',
        'fecha_radicacion',
        'canal_ingreso_id',
        'tipo_pqrsd_id',
        'motivo_pqrsd_id',
        'categoria_pqrsd_id',
        'subcategoria_pqrsd_id',
        'nivel_riesgo',
        'ciudadano_no_clasifica',
        'alerta_vital_preventiva',
        'regla_sla_id',
        'regla_sla_version',
        'fecha_inicio_conteo',
        'fecha_limite',
        'estado_operativo',
        'estado_cumplimiento',
        'es_anonimo',
        'anonimo_pendiente_validacion_juridica',
        'acepto_tratamiento_datos',
        'tratamiento_aceptado_en',
        'tratamiento_politica_version',
        'consentimiento_comunicacion_email',
        'origen_publico',
        'medio_respuesta',
        'solicitante_nombre',
        'solicitante_tipo_documento',
        'solicitante_documento',
        'solicitante_email',
        'solicitante_telefono',
        'solicitante_direccion',
        'paciente_nombre',
        'paciente_tipo_documento',
        'paciente_documento',
        'paciente_fecha_nacimiento',
        'paciente_edad',
        'representante_nombre',
        'aseguradora_id',
        'sede_id',
        'servicio_area',
        'descripcion',
        'observaciones_internas',
        'responsable_id',
        'creado_por',
        'radicado_por',
        'fecha_cierre',
        'fecha_respuesta',
        'respuesta_estado',
        'respuesta_elaborada_en',
        'respuesta_consulta_texto',
        'respuesta_puesta_disposicion_en',
        'respuesta_enviada_en',
        'respuesta_canal_comunicacion',
        'respuesta_evidencia_comunicacion',
        'respuesta_consultada_en',
    ];

    protected function casts(): array
    {
        return [
            'fecha_recepcion' => 'datetime',
            'fecha_radicacion' => 'datetime',
            'fecha_inicio_conteo' => 'datetime',
            'fecha_limite' => 'datetime',
            'fecha_cierre' => 'datetime',
            'fecha_respuesta' => 'datetime',
            'respuesta_elaborada_en' => 'datetime',
            'respuesta_puesta_disposicion_en' => 'datetime',
            'respuesta_enviada_en' => 'datetime',
            'respuesta_consultada_en' => 'datetime',
            'paciente_fecha_nacimiento' => 'date',
            'es_anonimo' => 'boolean',
            'anonimo_pendiente_validacion_juridica' => 'boolean',
            'acepto_tratamiento_datos' => 'boolean',
            'tratamiento_aceptado_en' => 'datetime',
            'ciudadano_no_clasifica' => 'boolean',
            'alerta_vital_preventiva' => 'boolean',
            'consentimiento_comunicacion_email' => 'boolean',
        ];
    }

    public function cumplimientoVigente(?\Carbon\CarbonInterface $ahora = null): string
    {
        return app(\App\Services\SlaCalculator::class)->calcularEstadoCumplimiento(
            $this->fecha_inicio_conteo ?? $this->fecha_recepcion,
            $this->fecha_limite,
            $this->nivel_riesgo,
            $ahora,
            respondido: $this->fecha_respuesta !== null,
            fechaRespuesta: $this->fecha_respuesta
        );
    }

    public function respuestaDisponibleParaConsulta(): bool
    {
        return in_array($this->respuesta_estado, ['puesta_disposicion', 'enviada', 'consultada'], true)
            && filled($this->respuesta_consulta_texto);
    }

    public function tieneClasificacionFormal(): bool
    {
        return $this->estado_operativo !== 'pendiente_clasificacion'
            && $this->nivel_riesgo !== 'pendiente'
            && $this->estado_cumplimiento !== 'sin_clasificar'
            && filled($this->tipo_pqrsd_id)
            && filled($this->fecha_limite);
    }

    public function tieneRespuestaElaborada(): bool
    {
        return filled($this->respuesta_consulta_texto)
            && in_array($this->respuesta_estado, ['elaborada', 'aprobada', 'puesta_disposicion', 'enviada', 'consultada'], true);
    }

    /**
     * Comunicación válida para cierre/oportunidad operativa.
     * Legacy: fecha_respuesta sin respuesta_estado (expedientes anteriores al modelo A/B/C).
     */
    public function tieneComunicacionValida(): bool
    {
        if (in_array($this->respuesta_estado, ['puesta_disposicion', 'enviada', 'consultada'], true)
            && (filled($this->respuesta_consulta_texto) || filled($this->fecha_respuesta))) {
            return true;
        }

        return filled($this->fecha_respuesta)
            && ($this->respuesta_estado === null || $this->respuesta_estado === '');
    }

    /**
     * @return list<string>
     */
    public function requisitosPendientesCierre(): array
    {
        $pendientes = [];

        if (config('pqrsd_sla.cierre_exige_clasificacion', true) && ! $this->tieneClasificacionFormal()) {
            $pendientes[] = 'Completar clasificación y evaluación de riesgo (con tipo y plazo formal).';
        }

        if (! $this->tieneRespuestaElaborada() && ! $this->tieneComunicacionValida()) {
            $pendientes[] = 'Elaborar y guardar el contenido de la respuesta de PQRS.';
        }

        if (config('pqrsd_sla.cierre_exige_comunicacion', true) && ! $this->tieneComunicacionValida()) {
            $pendientes[] = 'Registrar comunicación válida: puesta a disposición en consulta segura o envío por canal verificable con evidencia.';
        }

        return $pendientes;
    }

    public function puedeFinalizarse(): bool
    {
        return $this->estado_operativo !== 'cerrado' && $this->requisitosPendientesCierre() === [];
    }

    public function etiquetaProgresoFinalizacion(): string
    {
        if ($this->estado_operativo === 'cerrado') {
            return 'Finalizada';
        }

        if ($this->puedeFinalizarse()) {
            return 'Lista para finalizar';
        }

        if (! $this->tieneComunicacionValida()) {
            return 'En trámite — pendiente de respuesta';
        }

        return 'En trámite — completar requisitos de cierre';
    }

    /**
     * Token de versión optimista para formularios compartidos.
     * Combina marca temporal y campos mutables críticos (útil también con reloj congelado en pruebas).
     */
    public function versionToken(): string
    {
        return hash('sha256', implode('|', [
            (string) ($this->updated_at?->getTimestamp() ?? 0),
            (string) $this->nivel_riesgo,
            (string) $this->estado_operativo,
            (string) $this->estado_cumplimiento,
            (string) ($this->fecha_limite?->getTimestamp() ?? ''),
            (string) ($this->fecha_respuesta?->getTimestamp() ?? ''),
            (string) $this->respuesta_estado,
            hash('sha256', (string) $this->respuesta_consulta_texto),
            (string) ($this->fecha_cierre?->getTimestamp() ?? ''),
        ]));
    }

    public function assertAbiertoParaEscritura(): void
    {
        if ($this->estado_operativo === 'cerrado') {
            throw new \InvalidArgumentException(
                'El radicado está cerrado. Recargue el expediente; no se permiten nuevas modificaciones.'
            );
        }
    }

    /**
     * @param  bool  $obligatoria  true = HTTP operativo (no se puede omitir el token)
     */
    public function assertVersionCompatible(?string $expectedVersion, bool $obligatoria = false): void
    {
        if ($expectedVersion === null || $expectedVersion === '') {
            if ($obligatoria) {
                throw new \InvalidArgumentException(
                    'Debe recargar el expediente e incluir la versión actual antes de guardar.'
                );
            }

            return;
        }

        if ((string) $this->versionToken() !== (string) $expectedVersion) {
            throw new \InvalidArgumentException(
                'Otro usuario actualizó este expediente mientras usted trabajaba. Recargue la página y vuelva a intentar con la información actualizada.'
            );
        }
    }

    /**
     * Bloqueo pesimista + validación de abierto/versión dentro de una transacción activa.
     * Orden: lockForUpdate → abierto → versión (evita comparar sobre fila no bloqueada).
     *
     * @param  bool  $versionObligatoria  true en rutas HTTP de clasificar/responder/cerrar
     */
    public static function bloquearParaEscritura(
        int $id,
        ?string $expectedVersion = null,
        bool $versionObligatoria = false
    ): self {
        /** @var self $caso */
        $caso = static::query()->whereKey($id)->lockForUpdate()->firstOrFail();
        $caso->assertAbiertoParaEscritura();
        $caso->assertVersionCompatible($expectedVersion, $versionObligatoria);

        return $caso;
    }

    public function canal(): BelongsTo
    {
        return $this->belongsTo(CanalIngreso::class, 'canal_ingreso_id');
    }

    public function tipo(): BelongsTo
    {
        return $this->belongsTo(TipoPqrsd::class, 'tipo_pqrsd_id');
    }

    public function motivo(): BelongsTo
    {
        return $this->belongsTo(MotivoPqrsd::class, 'motivo_pqrsd_id');
    }

    public function categoria(): BelongsTo
    {
        return $this->belongsTo(CategoriaPqrsd::class, 'categoria_pqrsd_id');
    }

    public function subcategoria(): BelongsTo
    {
        return $this->belongsTo(SubcategoriaPqrsd::class, 'subcategoria_pqrsd_id');
    }

    public function encuesta(): HasOne
    {
        return $this->hasOne(EncuestaSatisfaccionPqrsd::class, 'pqrsd_id');
    }

    public function pendienteDeClasificacion(): bool
    {
        return $this->ciudadano_no_clasifica
            || $this->nivel_riesgo === 'pendiente'
            || $this->estado_operativo === 'pendiente_clasificacion';
    }

    public function segundosSinClasificacion(): ?int
    {
        if (! $this->pendienteDeClasificacion() || $this->fecha_recepcion === null) {
            return null;
        }

        return max(0, (int) $this->fecha_recepcion->diffInSeconds(now('America/Bogota')));
    }

    public function etiquetaEsperaClasificacion(): ?string
    {
        $segundos = $this->segundosSinClasificacion();
        if ($segundos === null) {
            return null;
        }

        $horas = intdiv($segundos, 3600);
        $minutos = intdiv($segundos % 3600, 60);
        if ($horas >= 48) {
            return intdiv($horas, 24).' d '.($horas % 24).' h';
        }

        return $horas.' h '.$minutos.' min';
    }

    public function esperaSuperaPlazoMasCorto(): bool
    {
        $segundos = $this->segundosSinClasificacion();

        return $segundos !== null && $segundos >= 8 * 3600;
    }

    public function reglaSla(): BelongsTo
    {
        return $this->belongsTo(ReglaSla::class, 'regla_sla_id');
    }

    public function aseguradora(): BelongsTo
    {
        return $this->belongsTo(Aseguradora::class);
    }

    public function sede(): BelongsTo
    {
        return $this->belongsTo(Sede::class);
    }

    public function responsable(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsable_id');
    }

    public function creador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creado_por');
    }

    public function radicador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'radicado_por');
    }

    public function asignaciones(): HasMany
    {
        return $this->hasMany(AsignacionPqrsd::class, 'pqrsd_id');
    }

    public function actuaciones(): HasMany
    {
        return $this->hasMany(ActuacionPqrsd::class, 'pqrsd_id')->latest();
    }

    public function ultimaActuacion(): HasOne
    {
        return $this->hasOne(ActuacionPqrsd::class, 'pqrsd_id')->latestOfMany();
    }

    public function historialEstados(): HasMany
    {
        return $this->hasMany(HistorialEstadoPqrsd::class, 'pqrsd_id')->latest();
    }

    public function adjuntos(): HasMany
    {
        return $this->hasMany(AdjuntoPqrsd::class, 'pqrsd_id');
    }

    public function remisionesInternas(): HasMany
    {
        return $this->hasMany(RemisionInterna::class, 'pqrsd_id')->latest();
    }

    public function auditorias(): HasMany
    {
        return $this->hasMany(AuditoriaPqrsd::class, 'pqrsd_id')->latest();
    }

    public function mensajesCorreo(): HasMany
    {
        return $this->hasMany(MensajeCorreo::class, 'pqrsd_id')->orderBy('fecha_mensaje');
    }
}
