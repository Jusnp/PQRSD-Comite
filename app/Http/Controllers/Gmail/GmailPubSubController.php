<?php

namespace App\Http\Controllers\Gmail;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessGmailPubSubNotificationJob;
use App\Models\GmailPubsubEvent;
use App\Services\Gmail\PubSubOidcVerifier;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

class GmailPubSubController extends Controller
{
    public function __invoke(Request $request, PubSubOidcVerifier $verifier): Response
    {
        $auth = $request->bearerToken();
        $audience = (string) config('pqrsd_correo.gmail.pubsub_push_audience');

        if (! $verifier->verify($auth, $audience)) {
            Log::channel('correo_sync')->warning('Pub/Sub push rechazado (OIDC)');

            return response('Unauthorized', 401);
        }

        $envelope = $request->all();
        $message = $envelope['message'] ?? [];
        $pubsubMessageId = $message['messageId'] ?? $message['message_id'] ?? null;

        if ($pubsubMessageId && GmailPubsubEvent::where('message_id', $pubsubMessageId)->exists()) {
            // Idempotencia: ack sin reprocesar
            return response('OK', 200);
        }

        $dataRaw = $message['data'] ?? '';
        $decoded = $dataRaw !== '' ? json_decode(base64_decode($dataRaw, true) ?: '', true) : null;
        $email = is_array($decoded) ? ($decoded['emailAddress'] ?? null) : null;
        $historyId = is_array($decoded) ? (isset($decoded['historyId']) ? (string) $decoded['historyId'] : null) : null;

        $event = GmailPubsubEvent::create([
            'message_id' => $pubsubMessageId ?: ('local-'.uniqid()),
            'email_address' => $email,
            'history_id' => $historyId,
            'estado' => 'encolado',
            'detalle' => 'Push recibido',
        ]);

        ProcessGmailPubSubNotificationJob::dispatch($event->id);

        return response('OK', 200);
    }
}
