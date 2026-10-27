<?php

namespace App\Mail\DTO;

use Carbon\CarbonInterface;

readonly class InboundMessageData
{
    /**
     * @param  array<int, array{nombre:string,contenido:string,mime:?string}>  $adjuntos
     * @param  array<string, mixed>  $metadatos
     */
    public function __construct(
        public string $messageId,
        public ?string $inReplyTo,
        public ?string $references,
        public string $fromAddress,
        public ?string $fromName,
        public ?string $toAddress,
        public ?string $cc,
        public ?string $originalRecipient,
        public ?string $subject,
        public ?string $bodyText,
        public ?string $bodyHtml,
        public ?string $rawHeaders,
        public ?string $rawEml,
        public CarbonInterface $date,
        public array $adjuntos = [],
        public array $metadatos = [],
    ) {}
}
