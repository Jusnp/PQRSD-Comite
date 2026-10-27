<?php

namespace App\Mail\Gmail;

/**
 * Cliente de pruebas: simula history, 404, auth y envío sin red.
 */
class FakeGmailApiClient implements GmailApiClient
{
    public bool $authOk = true;

    public bool $historyExpired = false;

    public bool $temporaryFailure = false;

    public bool $sendOk = true;

    public string $profileHistoryId = '100';

    /** @var array<int, array<string, mixed>> */
    public array $historyMessages = [];

    /** @var array<int, array<string, mixed>> */
    public array $recentMessages = [];

    /** @var array<string, array<string, mixed>> */
    public array $messagesById = [];

    public ?string $lastWatchTopic = null;

    public int $sendAttempts = 0;

    public function listHistory(string $userId, string $startHistoryId, ?string $pageToken = null): array
    {
        $this->assertAuth();
        if ($this->temporaryFailure) {
            throw GmailApiException::temporary('503 simulated');
        }
        if ($this->historyExpired) {
            throw GmailApiException::historyExpired('Requested entity was not found.');
        }

        return [
            'historyId' => (string) ((int) $startHistoryId + count($this->historyMessages) + 1),
            'messages' => $this->historyMessages,
        ];
    }

    public function listRecentMessages(string $userId, ?string $query = null, int $maxResults = 50): array
    {
        $this->assertAuth();

        return [
            'historyId' => $this->profileHistoryId,
            'messages' => array_slice($this->recentMessages, 0, $maxResults),
        ];
    }

    public function getMessage(string $userId, string $messageId, string $format = 'full'): array
    {
        $this->assertAuth();

        if (! isset($this->messagesById[$messageId])) {
            throw new GmailApiException('Mensaje no encontrado', 404);
        }

        return $this->messagesById[$messageId];
    }

    public function sendRaw(string $userId, string $rawRfc822): array
    {
        $this->sendAttempts++;
        $this->assertAuth();

        if (! $this->sendOk) {
            return ['ok' => false, 'id' => null, 'threadId' => null, 'error' => 'send failed'];
        }

        return ['ok' => true, 'id' => 'sent-'. $this->sendAttempts, 'threadId' => 'th-1', 'error' => null];
    }

    public function watch(string $userId, string $topicName, array $labelIds = ['INBOX']): array
    {
        $this->assertAuth();
        $this->lastWatchTopic = $topicName;

        return [
            'ok' => true,
            'historyId' => $this->profileHistoryId,
            'expiration' => (string) ((int) (microtime(true) * 1000) + 7 * 86400000),
            'error' => null,
        ];
    }

    public function getProfile(string $userId): array
    {
        $this->assertAuth();

        return [
            'ok' => true,
            'email' => $userId === 'me' ? 'buzon@menteplena.test' : $userId,
            'historyId' => $this->profileHistoryId,
            'error' => null,
        ];
    }

    private function assertAuth(): void
    {
        if (! $this->authOk) {
            throw GmailApiException::authFailed('token inválido');
        }
    }
}
