<?php

namespace App\Http\Controllers\Correo;

use App\Http\Controllers\Controller;
use App\Models\MensajeCorreo;
use App\Models\Pqrsd;
use App\Services\Correo\EmailReplyService;
use App\Services\Correo\EmailReviewService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class BandejaCorreoController extends Controller
{
    public function index(Request $request): View
    {
        $query = MensajeCorreo::with(['cuenta', 'pqrsd'])
            ->where('direccion', 'entrante')
            ->latest('fecha_mensaje');

        $filtro = $request->string('filtro', 'pendientes')->toString();

        match ($filtro) {
            'pendientes' => $query->where('clasificacion', 'pendiente_revision'),
            'respuestas' => $query->where('clasificacion', 'respuesta_caso'),
            'ruido' => $query->whereIn('clasificacion', ['ruido', 'descartado']),
            'todos' => null,
            default => $query->where('clasificacion', 'pendiente_revision'),
        };

        $items = $query->paginate(20)->withQueryString();

        return view('correo.bandeja.index', compact('items', 'filtro'));
    }

    public function show(MensajeCorreo $mensaje): View
    {
        $mensaje->load(['cuenta', 'pqrsd', 'adjuntos', 'revisor']);

        $hilo = MensajeCorreo::with('cuenta')
            ->where(function ($q) use ($mensaje) {
                $q->where('thread_key', $mensaje->thread_key);
                if ($mensaje->pqrsd_id) {
                    $q->orWhere('pqrsd_id', $mensaje->pqrsd_id);
                }
            })
            ->orderBy('fecha_mensaje')
            ->get();

        $casos = Pqrsd::query()->latest('id')->limit(50)->get(['id', 'radicado', 'solicitante_nombre']);

        return view('correo.bandeja.show', compact('mensaje', 'hilo', 'casos'));
    }

    public function confirmar(MensajeCorreo $mensaje, EmailReviewService $review): RedirectResponse
    {
        $mensaje = $review->confirmarNuevaPqrsd($mensaje, Auth::user());

        return redirect()
            ->route('pqrsd.show', $mensaje->pqrsd_id)
            ->with('success', 'PQRSD creada desde correo: '.$mensaje->radicado_detectado);
    }

    public function asociar(Request $request, MensajeCorreo $mensaje, EmailReviewService $review): RedirectResponse
    {
        $data = $request->validate([
            'pqrsd_id' => ['required', 'exists:pqrsd,id'],
        ]);

        $review->asociarACaso($mensaje, (int) $data['pqrsd_id'], Auth::user());

        return back()->with('success', 'Mensaje asociado al radicado.');
    }

    public function descartar(Request $request, MensajeCorreo $mensaje, EmailReviewService $review): RedirectResponse
    {
        $data = $request->validate([
            'motivo' => ['required', 'string', 'max:500'],
        ]);

        $review->descartar($mensaje, Auth::user(), $data['motivo']);

        return redirect()->route('bandeja-correo.index')->with('success', 'Mensaje descartado.');
    }

    public function responder(Request $request, MensajeCorreo $mensaje, EmailReplyService $reply): RedirectResponse
    {
        $data = $request->validate([
            'cuerpo' => ['required', 'string'],
            'asunto' => ['nullable', 'string', 'max:255'],
        ]);

        $resultado = $reply->responder(
            $mensaje,
            $data['cuerpo'],
            Auth::user(),
            $data['asunto'] ?? null
        );

        if (! $resultado['ok']) {
            return back()->withErrors(['cuerpo' => $resultado['error'] ?? 'No se pudo enviar']);
        }

        return back()->with('success', 'Respuesta enviada y registrada en el hilo.');
    }
}
