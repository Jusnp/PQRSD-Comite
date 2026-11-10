<?php

namespace Tests\Feature;

use App\Mail\Gmail\GmailApiException;
use App\Mail\Gmail\HttpGmailApiClient;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GmailListPaginationTest extends TestCase
{
    public function test_messages_list_recorre_next_page_token(): void
    {
        $primera = [];
        for ($i = 1; $i <= 50; $i++) {
            $primera[] = ['id' => 'm'.$i];
        }

        Http::fake(function ($request) use ($primera) {
            $url = $request->url();
            if (str_contains($url, '/profile')) {
                return Http::response(['emailAddress' => 'callcenter@menteplena.com.co', 'historyId' => '900'], 200);
            }
            if (str_contains($url, 'pageToken=pagina-2')) {
                return Http::response(['messages' => [['id' => 'm51']]], 200);
            }

            return Http::response([
                'messages' => $primera,
                'nextPageToken' => 'pagina-2',
            ], 200);
        });

        $resultado = (new HttpGmailApiClient('token-ficticio'))->listRecentMessages('me', 'after:2026/11/09 -in:spam', 50);

        $this->assertCount(51, $resultado['messages']);
        $this->assertSame('m1', $resultado['messages'][0]['id']);
        $this->assertSame('m51', $resultado['messages'][50]['id']);
        $this->assertNull($resultado['nextPageToken']);
        $this->assertSame('900', $resultado['historyId']);
        Http::assertSentCount(3);
    }

    public function test_history_list_recorre_next_page_token(): void
    {
        Http::fake(function ($request) {
            if (str_contains($request->url(), 'pageToken=hist-2')) {
                return Http::response([
                    'historyId' => '880',
                    'history' => [
                        ['messagesAdded' => [['message' => ['id' => 'h2']]]],
                    ],
                ], 200);
            }

            return Http::response([
                'historyId' => '880',
                'nextPageToken' => 'hist-2',
                'history' => [
                    ['messagesAdded' => [['message' => ['id' => 'h1']]]],
                    ['messagesAdded' => [['message' => ['id' => 'h1']]]],
                ],
            ], 200);
        });

        $resultado = (new HttpGmailApiClient('token-ficticio'))->listHistory('me', '100');

        $this->assertSame(['h1', 'h2'], array_column($resultado['messages'], 'id'));
        $this->assertNull($resultado['nextPageToken']);
        $this->assertSame('880', $resultado['historyId']);
    }

    public function test_un_error_en_la_segunda_pagina_no_devuelve_un_cursor_parcial(): void
    {
        Http::fake(function ($request) {
            if (str_contains($request->url(), 'pageToken=')) {
                return Http::response(['error' => 'unavailable'], 503);
            }

            return Http::response([
                'historyId' => '999',
                'nextPageToken' => 'hist-2',
                'history' => [
                    ['messagesAdded' => [['message' => ['id' => 'h1']]]],
                ],
            ], 200);
        });

        try {
            (new HttpGmailApiClient('token-ficticio'))->listHistory('me', '100');
            $this->fail('La segunda página debía interrumpir la lectura.');
        } catch (GmailApiException $e) {
            $this->assertStringContainsString('503', $e->getMessage());
        }

        Http::assertSentCount(2);
    }
}
