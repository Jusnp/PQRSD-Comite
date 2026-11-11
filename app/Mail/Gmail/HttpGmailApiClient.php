<?php

namespace App\Mail\Gmail;

use Illuminate\Http\Client\Response;
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

    private const PAGE_SIZE = 100;

    private const MAX_PAGES = 100;

    public function __construct(
        private readonly string $accessToken,
    ) {
        if ($accessToken === '') {
            throw GmailApiException::authFailed('access token vacío');
        }
    }

    public function listHistory(string $userId, string $startHistoryId, ?string $pageToken = null): array
    {
        $ids = [];
        $historyId = null;
        $vistos = [];
        $pagina = 0;

        do {
            $pagina++;
            if ($pagina > self::MAX_PAGES) {
                throw GmailApiException::temporary('history.list superó el máximo de páginas');
            }
            if ($pageToken) {
                if (isset($vistos[$pageToken])) {
                    throw GmailApiException::temporary('history.list repitió pageToken');
                }
                $vistos[$pageToken] = true;
            }

            $query = [
                'startHistoryId' => $startHistoryId,
                'historyTypes' => 'messageAdded',
                'maxResults' => self::PAGE_SIZE,
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
            foreach ($json['history'] ?? [] as $entry) {
                foreach ($entry['messagesAdded'] ?? [] as $added) {
                    $this->recordarId($ids, $added['message']['id'] ?? null);
                }
            }
            if (isset($json['historyId'])) {
                $historyId = (string) $json['historyId'];
            }
            $pageToken = filled($json['nextPageToken'] ?? null) ? (string) $json['nextPageToken'] : null;
        } while ($pageToken);

        return [
            'historyId' => $historyId,
            'messages' => array_values($ids),
            'nextPageToken' => null,
        ];
    }

    public function listRecentMessages(string $userId, ?string $query = null, int $maxResults = 50): array
    {
        $ids = [];
        $pageToken = null;
        $vistos = [];
        $pagina = 0;
        $tamano = max(1, min($maxResults, 500));

        do {
            $pagina++;
            if ($pagina > self::MAX_PAGES) {
                throw GmailApiException::temporary('messages.list superó el máximo de páginas');
            }
            if ($pageToken) {
                if (isset($vistos[$pageToken])) {
                    throw GmailApiException::temporary('messages.list repitió pageToken');
                }
                $vistos[$pageToken] = true;
            }

            $params = ['maxResults' => $tamano];
            if ($query) {
                $params['q'] = $query;
            }
            if ($pageToken) {
                $params['pageToken'] = $pageToken;
            }

            $response = $this->request('GET', "/users/{$userId}/messages", $params);
            $this->assertOk($response);
            $json = $response->json() ?? [];
            foreach ($json['messages'] ?? [] as $message) {
                $this->recordarId($ids, $message['id'] ?? null);
            }
            $pageToken = filled($json['nextPageToken'] ?? null) ? (string) $json['nextPageToken'] : null;
        } while ($pageToken);

        $profile = $this->getProfile($userId);

        return [
            'historyId' => $profile['historyId'] ?? null,
            'messages' => array_values($ids),
            'nextPageToken' => null,
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

    public function sendRaw(string $userId, string $rawRfc822, ?string $threadId = null): array
    {
        $encoded = rtrim(strtr(base64_encode($rawRfc822), '+/', '-_'), '=');
        $body = [
            'raw' => $encoded,
        ];

        if ($threadId !== null && $threadId !== '') {
            $body['threadId'] = $threadId;
        }

        $response = $this->request('POST', "/users/{$userId}/messages/send", [], $body);

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

    public function insertRaw(string $userId, string $rawRfc822, array $labelIds = ['INBOX', 'UNREAD']): array
    {
        $encoded = rtrim(strtr(base64_encode($rawRfc822), '+/', '-_'), '=');
        $response = $this->request('POST', "/users/{$userId}/messages", [], [
            'raw' => $encoded,
            'labelIds' => array_values($labelIds),
        ]);

        if (! $response->successful()) {
            Log::channel('correo_sync')->warning('Gmail insert falló', [
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
    private function request(string $method, string $path, array $query = [], ?array $jsonBody = null): Response
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

    /**
     * @param  array<string, array{id:string}>  $ids
     */
    private function recordarId(array &$ids, mixed $id): void
    {
        if (! is_string($id) || $id === '' || isset($ids[$id])) {
            return;
        }

        $ids[$id] = ['id' => $id];
    }

    private function assertOk(Response $response): void
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
