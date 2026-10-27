<?php

namespace App\Mail\Gmail;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Cliente HTTP real de Gmail API.
 * El access token se resuelve desde credential_ref (JSON OAuth o service account path).
 * Nunca registra el token en logs.
 */
class HttpGmailApiClient implements GmailApiClient
{
    private const BASE = 'https://gmail.googleapis.com/gmail/v1';

    public function __construct(
        private readonly string $accessToken,
    ) {
        if ($accessToken === '') {
            throw GmailApiException::authFailed('access token vacío');
        }
    }

    public function listHistory(string $userId, string $startHistoryId, ?string $pageToken = null): array
    {
        $query = [
            'startHistoryId' => $startHistoryId,
            'historyTypes' => 'messageAdded',
        ];
        if ($pageToken) {
            $query['pageToken'] = $pageToken;
        }

        $response = $this->request('GET', "/users/{$userId}/history", $query);

        if ($response->status() === 404) {
            throw GmailApiException::historyExpired('history.list 404');
        }
        $this->assertOk($response);

        $json = $response->json() ?? [];
        $messages = [];
        foreach ($json['history'] ?? [] as $entry) {
            foreach ($entry['messagesAdded'] ?? [] as $added) {
                if (! empty($added['message']['id'])) {
                    $messages[] = ['id' => $added['message']['id']];
                }
            }
        }

        return [
            'historyId' => isset($json['historyId']) ? (string) $json['historyId'] : null,
            'messages' => $messages,
        ];
    }

    public function listRecentMessages(string $userId, ?string $query = null, int $maxResults = 50): array
    {
        $params = ['maxResults' => $maxResults];
        if ($query) {
            $params['q'] = $query;
        }

        $response = $this->request('GET', "/users/{$userId}/messages", $params);
        $this->assertOk($response);
        $json = $response->json() ?? [];

        $profile = $this->getProfile($userId);

        return [
            'historyId' => $profile['historyId'] ?? null,
            'messages' => $json['messages'] ?? [],
        ];
    }

    public function getMessage(string $userId, string $messageId, string $format = 'full'): array
    {
        $response = $this->request('GET', "/users/{$userId}/messages/{$messageId}", [
            'format' => $format,
        ]);
        $this->assertOk($response);

        return $response->json() ?? [];
    }

    public function sendRaw(string $userId, string $rawRfc822): array
    {
        $encoded = rtrim(strtr(base64_encode($rawRfc822), '+/', '-_'), '=');
        $response = $this->request('POST', "/users/{$userId}/messages/send", [], [
            'raw' => $encoded,
        ]);

        if (! $response->successful()) {
            Log::channel('correo_sync')->warning('Gmail send falló', [
                'status' => $response->status(),
            ]);

            return [
                'ok' => false,
                'id' => null,
                'threadId' => null,
                'error' => 'HTTP '.$response->status(),
            ];
        }

        $json = $response->json() ?? [];

        return [
            'ok' => true,
            'id' => $json['id'] ?? null,
            'threadId' => $json['threadId'] ?? null,
            'error' => null,
        ];
    }

    public function watch(string $userId, string $topicName, array $labelIds = ['INBOX']): array
    {
        $response = $this->request('POST', "/users/{$userId}/watch", [], [
            'topicName' => $topicName,
            'labelIds' => $labelIds,
        ]);

        if (! $response->successful()) {
            return [
                'ok' => false,
                'historyId' => null,
                'expiration' => null,
                'error' => 'HTTP '.$response->status(),
            ];
        }

        $json = $response->json() ?? [];

        return [
            'ok' => true,
            'historyId' => isset($json['historyId']) ? (string) $json['historyId'] : null,
            'expiration' => isset($json['expiration']) ? (string) $json['expiration'] : null,
            'error' => null,
        ];
    }

    public function getProfile(string $userId): array
    {
        $response = $this->request('GET', "/users/{$userId}/profile");
        if (! $response->successful()) {
            return [
                'ok' => false,
                'email' => null,
                'historyId' => null,
                'error' => 'HTTP '.$response->status(),
            ];
        }

        $json = $response->json() ?? [];

        return [
            'ok' => true,
            'email' => $json['emailAddress'] ?? null,
            'historyId' => isset($json['historyId']) ? (string) $json['historyId'] : null,
            'error' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $query
     * @param  array<string, mixed>|null  $jsonBody
     */
    private function request(string $method, string $path, array $query = [], ?array $jsonBody = null): \Illuminate\Http\Client\Response
    {
        $pending = Http::withToken($this->accessToken)
            ->acceptJson()
            ->timeout(30);

        $url = self::BASE.$path;

        return match (strtoupper($method)) {
            'GET' => $pending->get($url, $query),
            'POST' => $pending->post($url, $jsonBody ?? []),
            default => throw new GmailApiException('Método HTTP no soportado'),
        };
    }

    private function assertOk(\Illuminate\Http\Client\Response $response): void
    {
        if ($response->status() === 401 || $response->status() === 403) {
            throw GmailApiException::authFailed('HTTP '.$response->status());
        }

        if (in_array($response->status(), [429, 500, 502, 503, 504], true)) {
            throw GmailApiException::temporary('HTTP '.$response->status(), $response->status());
        }

        if (! $response->successful()) {
            throw new GmailApiException('Gmail API error HTTP '.$response->status(), $response->status());
        }
    }
}
