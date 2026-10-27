<?php

namespace App\Contracts;

use App\Mail\DTO\InboundMessageData;
use App\Mail\DTO\OutboundMessageData;
use App\Models\CuentaCorreo;
use Illuminate\Support\Collection;

interface MailProvider
{
    public function driver(): string;

    /**
     * @return Collection<int, InboundMessageData>
     */
    public function fetchNew(CuentaCorreo $cuenta, ?string $cursor = null): Collection;

    /**
     * @return array{ok:bool,detalle:?string}
     */
    public function testConnection(CuentaCorreo $cuenta): array;

    /**
     * @return array{ok:bool,message_id:?string,error:?string}
     */
    public function send(CuentaCorreo $cuenta, OutboundMessageData $mensaje): array;
}
