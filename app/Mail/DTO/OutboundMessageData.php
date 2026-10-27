<?php

namespace App\Mail\DTO;

readonly class OutboundMessageData
{
    /**
     * @param  array<int, string>  $cc
     * @param  array<int, array{nombre:string,ruta:string,mime:?string}>  $adjuntos
     */
    public function __construct(
        public string $to,
        public string $subject,
        public string $bodyText,
        public ?string $bodyHtml = null,
        public ?string $inReplyTo = null,
        public ?string $references = null,
        public array $cc = [],
        public array $adjuntos = [],
    ) {}
}
