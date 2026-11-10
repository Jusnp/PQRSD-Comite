<?php

namespace App\Http\Controllers\Correo;

use App\Http\Controllers\Controller;
use App\Models\AdjuntoCorreo;
use App\Models\Aseguradora;
use App\Models\MensajeCorreo;
use App\Models\MotivoPqrsd;
use App\Models\Pqrsd;
use App\Models\Sede;
use App\Models\TipoPqrsd;
use App\Services\Correo\EmailReplyService;
use App\Services\Correo\EmailReviewService;
use App\Services\PqrsdService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class BandejaCorreoController extends Controller
{
    public function index(Request $request): View
    {
        $this->asegurarRevision();

        $query = MensajeCorreo::with(['cuenta', 'pqrsd'])
            ->visiblesPara(Auth::user())
            ->where('direccion', 'entrante')
            ->latest('fecha_mensaje');

        $filtro = $request->string('filtro', 'pendientes')->toString();

        match ($filtro) {
            'pendientes' => $query->sinExpediente(),
            'radicados' => $query->whereNotNull('pqrsd_id'),
            'respuestas' => $query->where('clasificacion', 'respuesta_caso'),
            'ruido' => $query->whereIn('clasificacion', ['ruido', 'descartado']),
            'todos' => null,
            default => $query->sinExpediente(),
        };

        $items = $query->paginate(20)->withQueryString();

        return view('correo.bandeja.index', compact('items', 'filtro'));
    }

    public function show(MensajeCorreo $mensaje): View
    {
        $this->asegurarVisible($mensaje);
        $mensaje->load(['cuenta.aseguradora', 'pqrsd', 'adjuntos', 'revisor']);

        $hilo = MensajeCorreo::with('cuenta')
            ->where(function ($q) use ($mensaje) {
                $q->whereKey($mensaje->id);
                if (filled($mensaje->thread_key)) {
                    $q->orWhere(function ($hiloCuenta) use ($mensaje) {
                        $hiloCuenta->where('thread_key', $mensaje->thread_key)
                            ->where('cuenta_correo_id', $mensaje->cuenta_correo_id);
                    });
                }
                if ($mensaje->pqrsd_id) {
                    $q->orWhere(function ($mismoExpediente) use ($mensaje) {
                        $mismoExpediente->where('pqrsd_id', $mensaje->pqrsd_id)
                            ->where('cuenta_correo_id', $mensaje->cuenta_correo_id);
                    });
                }
            });
        $usuario = Auth::user();
        if ($usuario && ! $usuario->can('pqrsd.view_all')) {
            $hilo->where(function ($q) use ($mensaje) {
                $q->whereKey($mensaje->id)
                    ->orWhere(fn ($permitidos) => $permitidos->visiblesPara(Auth::user()));
            });
        }
        $hilo = $hilo->orderBy('fecha_mensaje')->get();

        $casos = $this->casosAsociables($mensaje);

        return view('correo.bandeja.show', compact('mensaje', 'hilo', 'casos'));
    }

    public function radicar(MensajeCorreo $mensaje): View|RedirectResponse
    {
        $this->asegurarVisible($mensaje);
        $mensaje->load('cuenta.aseguradora');
        if ($mensaje->pqrsd_id || $mensaje->clasificacion !== 'pendiente_revision') {
            return redirect()
                ->route('bandeja-correo.show', $mensaje)
                ->with('error', 'Este correo ya fue revisado.');
        }

        return view('correo.bandeja.radicar', array_merge(
            ['mensaje' => $mensaje],
            $this->catalogos()
        ));
    }

    public function radicarStore(Request $request, MensajeCorreo $mensaje, EmailReviewService $review): RedirectResponse
    {
        $this->asegurarVisible($mensaje);
        $mensaje->load('cuenta');
        $data = $request->validate([
            'solicitante_nombre' => [
                'nullable',
                'string',
                'max:255',
                Rule::requiredIf(fn () => ! $request->boolean('es_anonimo')),
            ],
            'solicitante_email' => ['nullable', 'email', 'max:255'],
            'solicitante_telefono' => ['nullable', 'string', 'max:30'],
            'solicitante_tipo_documento' => ['nullable', 'string', 'max:10'],
            'solicitante_documento' => ['nullable', 'string', 'max:30'],
            'tipo_pqrsd_id' => ['nullable', 'exists:tipos_pqrsd,id'],
            'motivo_pqrsd_id' => ['nullable', 'exists:motivos_pqrsd,id'],
            'nivel_riesgo' => ['required', 'in:pendiente,vital_menor,vital_general,priorizado,simple,ordinario'],
            'sede_id' => ['nullable', 'exists:sedes,id'],
            'aseguradora_id' => ['nullable', 'exists:aseguradoras,id'],
            'descripcion' => ['required', 'string', 'min:10'],
            'es_anonimo' => ['sometimes', 'boolean'],
        ]);
        $data['es_anonimo'] = $request->boolean('es_anonimo');

        try {
            $mensaje = $review->confirmarNuevaPqrsd($mensaje, Auth::user(), $data);
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['descripcion' => $e->getMessage()])->withInput();
        }

        return redirect()
            ->route('pqrsd.show', $mensaje->pqrsd_id)
            ->with('success', 'Correo radicado: '.$mensaje->pqrsd->radicado);
    }

    public function confirmar(MensajeCorreo $mensaje): RedirectResponse
    {
        $this->asegurarVisible($mensaje);

        return redirect()
            ->route('bandeja-correo.radicar', $mensaje)
            ->with('error', 'Revise y complete los datos antes de radicar el correo.');
    }

    public function asociar(Request $request, MensajeCorreo $mensaje, EmailReviewService $review): RedirectResponse
    {
        $this->asegurarVisible($mensaje);
        $data = $request->validate([
            'pqrsd_id' => ['required', 'exists:pqrsd,id'],
        ]);
        $this->authorize('view', Pqrsd::query()->findOrFail($data['pqrsd_id']));

        try {
            $review->asociarACaso($mensaje, (int) $data['pqrsd_id'], Auth::user());
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['pqrsd_id' => $e->getMessage()]);
        }

        return back()->with('success', 'Mensaje asociado al radicado.');
    }

    public function descartar(Request $request, MensajeCorreo $mensaje, EmailReviewService $review): RedirectResponse
    {
        $this->asegurarVisible($mensaje);
        $data = $request->validate([
            'motivo' => ['required', 'string', 'max:500'],
        ]);

        try {
            $review->descartar($mensaje, Auth::user(), $data['motivo']);
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['motivo' => $e->getMessage()]);
        }

        return redirect()->route('bandeja-correo.index')->with('success', 'Mensaje descartado.');
    }

    public function responder(Request $request, MensajeCorreo $mensaje, EmailReplyService $reply): RedirectResponse
    {
        abort_unless(Auth::user()?->can('correos.reply'), 403);
        $this->asegurarVisible($mensaje);
        $mensaje->loadMissing('cuenta');
        if (! $mensaje->cuenta?->activo) {
            return back()->withErrors([
                'cuerpo' => 'Envío deshabilitado: cuenta de correo inactiva',
            ]);
        }
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

    public function descargarAdjunto(MensajeCorreo $mensaje, AdjuntoCorreo $adjunto, PqrsdService $pqrsdService): StreamedResponse
    {
        $this->asegurarVisible($mensaje);
        if ((int) $adjunto->mensaje_correo_id !== (int) $mensaje->id) {
            abort(404);
        }

        $ruta = str_replace('\\', '/', (string) $adjunto->ruta);
        if (! $this->rutaAdjuntoPermitida($ruta)) {
            abort(404);
        }

        $disk = Storage::disk(config('pqrsd_correo.adjuntos_disk', 'local'));
        if (! $disk->exists($ruta)) {
            abort(404);
        }

        $pqrsdService->auditar($mensaje->pqrsd, Auth::user(), 'correo_adjunto_descargado', null, [
            'mensaje_correo_id' => $mensaje->id,
            'adjunto_correo_id' => $adjunto->id,
        ], 'adjunto_correo', $adjunto->id);

        return $disk->download($ruta, $this->nombreAdjunto($adjunto->nombre_original), [
            'Content-Type' => $adjunto->mime ?: 'application/octet-stream',
        ]);
    }

    private function asegurarRevision(): void
    {
        abort_unless(Auth::user()?->can('correos.review'), 403);
    }

    private function asegurarVisible(MensajeCorreo $mensaje): void
    {
        $this->asegurarRevision();
        $visible = MensajeCorreo::query()->visiblesPara(Auth::user())->whereKey($mensaje->id)->exists();
        abort_unless($visible, 404);
    }

    private function rutaAdjuntoPermitida(string $ruta): bool
    {
        $base = trim((string) config('pqrsd_correo.adjuntos_path', 'correo/adjuntos'), '/');

        return $ruta !== ''
            && ! str_contains($ruta, "\0")
            && ! str_contains($ruta, '..')
            && ! str_starts_with($ruta, '/')
            && ! preg_match('/^[A-Za-z]:/', $ruta)
            && ($ruta === $base || str_starts_with($ruta, $base.'/'));
    }

    private function nombreAdjunto(?string $nombre): string
    {
        $limpio = basename(str_replace('\\', '/', (string) $nombre));
        $limpio = trim(str_replace(["\r", "\n", '"'], '', $limpio));

        return ($limpio === '' || $limpio === '.' || $limpio === '..') ? 'adjunto' : $limpio;
    }

    private function casosAsociables(MensajeCorreo $mensaje): Collection
    {
        $casos = Pqrsd::query()->latest('id');
        if ($mensaje->cuenta?->aseguradora_id) {
            $casos->where('aseguradora_id', $mensaje->cuenta->aseguradora_id);
        }
        $usuario = Auth::user();
        if (! $usuario->can('pqrsd.view_all')) {
            $casos->where(function ($q) use ($usuario) {
                $q->where('responsable_id', $usuario->id)
                    ->orWhere('creado_por', $usuario->id)
                    ->orWhere('radicado_por', $usuario->id);
            });
        }

        return $casos->limit(50)->get(['id', 'radicado', 'solicitante_nombre']);
    }

    /**
     * @return array{tipos:Collection,motivos:Collection,sedes:Collection,aseguradoras:Collection}
     */
    private function catalogos(): array
    {
        return [
            'tipos' => TipoPqrsd::query()->where('activo', true)->orderBy('orden')->get(),
            'motivos' => MotivoPqrsd::query()->where('activo', true)->orderBy('nombre')->get(),
            'sedes' => Sede::query()->orderBy('nombre')->get(),
            'aseguradoras' => Aseguradora::query()->where('activo', true)->orderBy('nombre')->get(),
        ];
    }
}
