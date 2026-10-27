<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CuentaCorreo extends Model
{
    protected $table = 'cuentas_correo';

    protected $fillable = [
        'direccion',
        'descripcion',
        'aseguradora_id',
        'sede_id',
        'area_responsable',
        'responsable_nombre',
        'activo',
        'es_buzon_central',
        'tipo_buzon',
        'buzon_canonico',
        'tipo_integracion',
        'credential_ref',
        'host',
        'puerto',
        'cifrado',
        'config_extra',
        'estado_conexion',
        'estado_autorizacion',
        'ultima_sincronizacion',
        'ultimo_error',
        'intentos_fallidos',
        'cursor_sincronizacion',
        'watch_expira_en',
        'ultimo_history_id',
        'fecha_ultima_validacion_inventario',
        'observaciones_inventario',
    ];

    protected function casts(): array
    {
        return [
            'activo' => 'boolean',
            'es_buzon_central' => 'boolean',
            'config_extra' => 'array',
            'ultima_sincronizacion' => 'datetime',
            'watch_expira_en' => 'datetime',
            'fecha_ultima_validacion_inventario' => 'datetime',
        ];
    }

    public function oauthToken(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(GmailOauthToken::class, 'cuenta_correo_id');
    }

    public function aseguradora(): BelongsTo
    {
        return $this->belongsTo(Aseguradora::class);
    }

    public function sede(): BelongsTo
    {
        return $this->belongsTo(Sede::class);
    }

    public function mensajes(): HasMany
    {
        return $this->hasMany(MensajeCorreo::class, 'cuenta_correo_id');
    }

    public function sincronizaciones(): HasMany
    {
        return $this->hasMany(SincronizacionCorreo::class, 'cuenta_correo_id');
    }
}
