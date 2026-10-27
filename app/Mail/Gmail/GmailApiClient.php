<?php

namespace App\Mail\Gmail;

/**
 * Cliente HTTP abstracto de Gmail API (inyectable / fakeable en tests).
 * No registra tokens ni cuerpos de correo sensibles.
 */
interface GmailApiClient
{
    /**
     * @return array{historyId:?string,messages:array<int, array<string, mixed>>}
     *
     * @throws GmailApiException
     */
    public function listHistory(string $userId, string $startHistoryId, ?string $pageToken = null): array;

    /**
     * Recuperación cuando historyId expiró (HTTP 404).
     *
     * @return array{historyId:?string,messages:array<int, array<string, mixed>>}
     */
    public function listRecentMessages(string $userId, ?string $query = null, int $maxResults = 50): array;

    /**
     * @return array<string, mixed>
     */
    public function getMessage(string $userId, string $messageId, string $format = 'full'): array;

    /**
     * @return array{ok:bool,id:?string,threadId:?string,error:?string}
     */
    public function sendRaw(string $userId, string $rawRfc822): array;

    /**
     * @return array{ok:bool,historyId:?string,expiration:?string,error:?string}
     */
    public function watch(string $userId, string $topicName, array $labelIds = ['INBOX']): array;

    /**
     * @return array{ok:bool,email:?string,historyId:?string,error:?string}
     */
    public function getProfile(string $userId): array;
}
