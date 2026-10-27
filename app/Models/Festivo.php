<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Festivo extends Model
{
    protected $fillable = [
        'fecha',
        'nombre',
        'ambito',
    ];

    protected function casts(): array
    {
        return [
            'fecha' => 'date',
        ];
    }
}
