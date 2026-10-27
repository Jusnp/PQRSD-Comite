<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GmailPubsubEvent extends Model
{
    protected $table = 'gmail_pubsub_events';

    protected $fillable = [
        'message_id',
        'cuenta_correo_id',
        'email_address',
        'history_id',
        'estado',
        'detalle',
    ];

    public function cuenta(): BelongsTo
    {
        return $this->belongsTo(CuentaCorreo::class, 'cuenta_correo_id');
    }
}
