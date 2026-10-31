<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContactoRegularizacionRadicado extends Model
{
    protected $table = 'contactos_regularizacion_radicado';

    protected $fillable = [
        'pqrsd_id',
        'usuario_id',
        'medio',
        'resultado',
        'fecha_contacto',
        'observacion',
    ];

    protected function casts(): array
    {
        return [
            'fecha_contacto' => 'datetime',
        ];
    }

    public function pqrsd(): BelongsTo
    {
        return $this->belongsTo(Pqrsd::class, 'pqrsd_id');
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_id');
    }

    public function etiquetaResultado(): string
    {
        return match ($this->resultado) {
            'contactado' => 'Contactado',
            'no_contactado' => 'No fue posible el contacto',
            'numero_no_valido' => 'El número no es válido',
            default => 'Resultado sin clasificar',
        };
    }
}
