<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EjecucionProgramada extends Model
{
    protected $table = 'ejecuciones_programadas';

    protected $fillable = [
        'comando',
        'estado',
        'procesados',
        'detalle',
        'iniciada_en',
        'finalizada_en',
    ];

    protected function casts(): array
    {
        return [
            'iniciada_en' => 'datetime',
            'finalizada_en' => 'datetime',
        ];
    }
}
