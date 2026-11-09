<?php

namespace App\Http\Controllers\Correo;

use App\Http\Controllers\Controller;
use App\Jobs\ProcesarSalidaCorreoJob;
use App\Models\CorreoSalida;
use App\Models\Pqrsd;
use App\Services\Correo\EmailReplyService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class CorreoSalidaController extends Controller
{
    public function reintentar(Pqrsd $pqrsd, CorreoSalida $salida): RedirectResponse
    {
        $this->authorize('update', $pqrsd);
        abort_unless($salida->pqrsd_id === $pqrsd->id, 404);

        if ($salida->estado === 'enviado' || filled($salida->gmail_message_id) || $salida->proveedor_invocado) {
            return back()->with('error', 'Ese aviso ya fue intentado. No se envía otra vez.');
        }

        $salida->update([
            'estado' => 'pendiente',
            'ultimo_error' => null,
        ]);
        ProcesarSalidaCorreoJob::dispatch($salida->id);

        return back()->with('success', 'El aviso volvió a la cola. No se contactó el buzón si aún no hay credencial.');
    }

    public function enviarDesdeExpediente(Request $request, Pqrsd $pqrsd, EmailReplyService $respuestas): RedirectResponse
    {
        $this->authorize('view', $pqrsd);

        $data = $request->validate([
            'destinatario' => ['required', 'email', 'max:255'],
            'cuerpo' => ['required', 'string', 'min:10', 'max:5000'],
        ]);

        $resultado = $respuestas->responderDesdeExpediente(
            $pqrsd,
            $data['cuerpo'],
            Auth::user(),
            $data['destinatario']
        );

        if (! $resultado['ok']) {
            return back()->withErrors(['cuerpo' => $resultado['error'] ?? 'No se pudo enviar'])->withInput();
        }

        return back()->with('success', 'La respuesta quedó registrada en el buzón de la cuenta. No confirma que el ciudadano la haya leído.');
    }
}
