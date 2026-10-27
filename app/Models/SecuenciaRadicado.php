<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SecuenciaRadicado extends Model
{
    protected $table = 'secuencias_radicado';

    protected $fillable = [
        'anio',
        'ultimo_numero',
    ];
}
