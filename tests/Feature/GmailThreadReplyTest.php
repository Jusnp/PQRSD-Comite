<?php

namespace Tests\Feature;

use App\Mail\Gmail\HttpGmailApiClient;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GmailThreadReplyTest extends TestCase
{
    public function test_send_raw_includes_thread_id_when_replying(): void
    {
        $payload = [];

        Http::fake(function ($request) use (&$payload) {
            $payload = json_decode($request->body(), true) ?? [];

            return Http::response([
                'id' => 'sent-1',
                'threadId' => 'thread-original-123',
            ], 200);
        });

        $raw = "Subject: Re: Test\r\n\r\nBody";

        $result = (new HttpGmailApiClient('token-test'))
            ->sendRaw('me', $raw, 'thread-original-123');

        $this->assertTrue($result['ok']);
        $this->assertSame('thread-original-123', $payload['threadId'] ?? null);
        $this->assertSame(
            rtrim(strtr(base64_encode($raw), '+/', '-_'), '='),
            $payload['raw'] ?? null
        );
    }

    public function test_send_raw_omits_thread_id_for_new_message(): void
    {
        $payload = [];

        Http::fake(function ($request) use (&$payload) {
            $payload = json_decode($request->body(), true) ?? [];

            return Http::response([
                'id' => 'sent-2',
                'threadId' => 'thread-new',
            ], 200);
        });

        $raw = "Subject: New message\r\n\r\nBody";

        $result = (new HttpGmailApiClient('token-test'))
            ->sendRaw('me', $raw);

        $this->assertTrue($result['ok']);
        $this->assertArrayNotHasKey('threadId', $payload);
    }
}
