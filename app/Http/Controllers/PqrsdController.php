<?php

namespace App\Http\Controllers;

use App\Models\ActuacionPqrsd;
use App\Models\Aseguradora;
use App\Models\CanalIngreso;
use App\Models\MotivoPqrsd;
use App\Models\Pqrsd;
use App\Models\Sede;
use App\Models\TipoPqrsd;
use App\Models\User;
use App\Services\AdjuntoPqrsdService;
use App\Services\PqrsdService;
use App\Services\VencimientoService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class PqrsdController extends Controller
{
    public function __construct(
        private readonly PqrsdService $pqrsdService,
        private readonly VencimientoService $vencimientoService,
        private readonly AdjuntoPqrsdService $adjuntoPqrsdService,
    ) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Pqrsd::class);

        $query = Pqrsd::with([
            'canal',
            'tipo',
            'motivo',
            'sede',
            'aseguradora',
            'ultimaActuacion.usuario',
        ])->withCount('adjuntos');

        if (! Auth::user()->can('pqrsd.view_all')) {
            $uid = Auth::id();
            $query->where(function ($q) use ($uid) {
                $q->where('responsable_id', $uid)
                    ->orWhere('creado_por', $uid)
                    ->orWhere('radicado_por', $uid);
            });
        }

        if ($request->filled('q')) {
            $q = $request->string('q');
            $query->where(function ($builder) use ($q) {
                $builder->where('radicado', 'like', "%{$q}%")
                    ->orWhere('solicitante_nombre', 'like', "%{$q}%")
                    ->orWhere('paciente_nombre', 'like', "%{$q}%");
            });
        }

        if ($request->filled('estado_operativo')) {
            $query->where('estado_operativo', $request->string('estado_operativo'));
        }

        if ($request->filled('nivel_riesgo')) {
            $query->where('nivel_riesgo', $request->string('nivel_riesgo'));
        }

        if ($request->filled('estado_cumplimiento')) {
            app(\App\Services\SlaCalculator::class)->restringirConsulta(
                $query,
                (string) $request->string('estado_cumplimiento')
            );
        }

        if ($request->filled('sede_id')) {
            $query->where('sede_id', $request->integer('sede_id'));
        }

        if ($request->filled('aseguradora_id')) {
            $query->where('aseguradora_id', $request->integer('aseguradora_id'));
        }

        if ($request->filled('tipo_pqrsd_id')) {
            $query->where('tipo_pqrsd_id', $request->integer('tipo_pqrsd_id'));
        }

        if ($request->filled('motivo_pqrsd_id')) {
            if ($request->string('motivo_pqrsd_id') === 'sin_motivo') {
                $query->whereNull('motivo_pqrsd_id');
            } else {
                $query->where('motivo_pqrsd_id', $request->integer('motivo_pqrsd_id'));
            }
        }

        if ($request->filled('canal_ingreso_id')) {
            $query->where('canal_ingreso_id', $request->integer('canal_ingreso_id'));
        }

        if ($request->filled('fecha_desde')) {
            $query->whereDate('fecha_recepcion', '>=', $request->date('fecha_desde'));
        }

        if ($request->filled('fecha_hasta')) {
            $query->whereDate('fecha_recepcion', '<=', $request->date('fecha_hasta'));
        }

        if ($request->filled('es_anonimo')) {
            $query->where('es_anonimo', $request->boolean('es_anonimo'));
        }

        if ($request->filled('medio_respuesta')) {
            $query->where('medio_respuesta', $request->string('medio_respuesta'));
        }

        $sort = $request->string('sort', 'prioridad')->toString();
        $dir = $request->string('dir', 'asc')->toString() === 'desc' ? 'desc' : 'asc';

        match ($sort) {
            'fecha_recepcion' => $query->orderBy('fecha_recepcion', $dir),
            'fecha_limite' => $query->orderBy('fecha_limite', $dir),
            'nivel_riesgo' => $query->orderBy('nivel_riesgo', $dir),
            'radicado' => $query->orderBy('radicado', $dir),
            default => $query
                ->orderByRaw("CASE
                    WHEN estado_cumplimiento = 'vencido' THEN 0
                    WHEN estado_cumplimiento = 'proximo_vencer' THEN 1
                    WHEN estado_operativo = 'pendiente_clasificacion' OR nivel_riesgo = 'pendiente' OR estado_cumplimiento = 'sin_clasificar' THEN 2
                    WHEN nivel_riesgo IN ('vital_menor', 'vital_general') THEN 3
                    WHEN estado_operativo IN ('asignado', 'en_gestion', 'radicado') THEN 4
                    ELSE 5
                END")
                ->orderBy('fecha_limite')
                ->orderByDesc('fecha_recepcion'),
        };

        $items = $query->paginate(15)->withQueryString();

        $motivosQuery = MotivoPqrsd::where('activo', true)->orderBy('nombre');
        if ($request->filled('tipo_pqrsd_id')) {
            $motivosQuery->where('tipo_pqrsd_id', $request->integer('tipo_pqrsd_id'));
        }

        $avanzadosAbiertos = $request->filled('motivo_pqrsd_id')
            || $request->filled('fecha_desde')
            || $request->filled('fecha_hasta')
            || $request->filled('canal_ingreso_id')
            || $request->filled('estado_cumplimiento')
            || $request->filled('es_anonimo')
            || $request->filled('medio_respuesta')
            || $request->boolean('avanzados');

        return view('pqrsd.index', [
            'items' => $items,
            'sedes' => Sede::orderBy('nombre')->get(),
            'aseguradoras' => Aseguradora::orderBy('nombre')->get(),
            'tipos' => TipoPqrsd::where('activo', true)->orderBy('orden')->get(),
            'motivos' => $motivosQuery->get(),
            'canales' => CanalIngreso::where('activo', true)->orderBy('nombre')->get(),
            'mediosRespuesta' => config('pqrsd_publico.medios_respuesta', []),
            'sort' => $sort,
            'dir' => $dir,
            'avanzadosAbiertos' => $avanzadosAbiertos,
        ]);
    }

    public function create(): View
    {
        $this->authorize('create', Pqrsd::class);

        return view('pqrsd.create', $this->formData());
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', Pqrsd::class);

        $data = $request->validate([
            'fecha_recepcion' => ['nullable', 'date'],
            'canal_ingreso_id' => ['required', 'exists:canales_ingreso,id'],
            'tipo_pqrsd_id' => ['nullable', 'exists:tipos_pqrsd,id'],
            'motivo_pqrsd_id' => ['nullable', 'exists:motivos_pqrsd,id'],
            'nivel_riesgo' => ['required', 'in:pendiente,vital_menor,vital_general,priorizado,simple,ordinario'],
            'es_anonimo' => ['sometimes', 'boolean'],
            'solicitante_nombre' => ['nullable', 'string', 'max:255'],
            'solicitante_tipo_documento' => ['nullable', 'string', 'max:10'],
            'solicitante_documento' => ['nullable', 'string', 'max:30'],
            'solicitante_email' => ['nullable', 'email', 'max:255'],
            'solicitante_telefono' => ['nullable', 'string', 'max:30'],
            'paciente_nombre' => ['nullable', 'string', 'max:255'],
            'paciente_tipo_documento' => ['nullable', 'string', 'max:10'],
            'paciente_documento' => ['nullable', 'string', 'max:30'],
            'paciente_fecha_nacimiento' => ['nullable', 'date', 'before_or_equal:today'],
            'representante_nombre' => ['nullable', 'string', 'max:255'],
            'aseguradora_id' => ['nullable', 'exists:aseguradoras,id'],
            'sede_id' => ['nullable', 'exists:sedes,id'],
            'servicio_area' => ['nullable', 'string', 'max:255'],
            'descripcion' => ['required', 'string'],
            'observaciones_internas' => ['nullable', 'string'],
            'responsable_id' => ['nullable', 'exists:users,id'],
        ]);

        $data['es_anonimo'] = $request->boolean('es_anonimo');

        $caso = $this->pqrsdService->radicarManual($data, Auth::user());

        return redirect()
            ->route('pqrsd.show', $caso)
            ->with('success', 'PQRSD radicada: '.$caso->radicado);
    }

    public function show(Pqrsd $pqrsd): View
    {
        $this->authorize('view', $pqrsd);

        $this->vencimientoService->refrescarCumplimiento($pqrsd);
        $pqrsd->refresh();

        $pqrsd->load([
            'canal',
            'tipo',
            'motivo',
            'sede',
            'aseguradora',
            'responsable',
            'reglaSla',
            'actuaciones.usuario',
            'ultimaActuacion.usuario',
            'historialEstados.usuario',
            'adjuntos',
            'auditorias.usuario',
            'remisionesInternas.area',
            'remisionesInternas.responsableSeguimiento',
        ]);

        $plazo = $this->vencimientoService->snapshot($pqrsd);
        $notificaciones = \App\Models\NotificacionPqrsd::where('pqrsd_id', $pqrsd->id)
            ->latest()
            ->limit(20)
            ->get();

        return view('pqrsd.show', [
            'pqrsd' => $pqrsd,
            'plazo' => $plazo,
            'notificaciones' => $notificaciones,
            'tipos' => TipoPqrsd::where('activo', true)->orderBy('orden')->get(),
            'areas' => \App\Models\AreaInstitucional::where('activo', true)->orderBy('orden')->orderBy('nombre')->get(),
        ]);
    }

    public function clasificar(Request $request, Pqrsd $pqrsd): RedirectResponse
    {
        $this->authorize('classify', $pqrsd);

        $data = $request->validate([
            'nivel_riesgo' => ['required', 'in:vital_menor,vital_general,priorizado,simple,ordinario'],
            'tipo_pqrsd_id' => ['nullable', 'exists:tipos_pqrsd,id'],
            'justificacion' => ['required', 'string', 'min:10', 'max:1000'],
            'forzar_recalculo' => ['sometimes', 'boolean'],
            'expediente_version' => ['required', 'string', 'size:64', 'regex:/^[a-f0-9]{64}$/i'],
        ]);

        try {
            $this->vencimientoService->aplicarClasificacion(
                $pqrsd,
                $data['nivel_riesgo'],
                Auth::user(),
                $data['justificacion'],
                $request->boolean('forzar_recalculo'),
                $data['expediente_version'],
                versionObligatoria: true
            );

            $pqrsd->refresh();

            if (! empty($data['tipo_pqrsd_id'])) {
                $this->pqrsdService->registrarCambioEstado(
                    $pqrsd->fresh(),
                    Auth::user(),
                    'tipo_pqrsd_id',
                    (string) $data['tipo_pqrsd_id'],
                    $data['justificacion']
                );
                $pqrsd->refresh();
            }

            if ($pqrsd->estado_operativo === 'pendiente_clasificacion') {
                $this->pqrsdService->registrarCambioEstado(
                    $pqrsd->fresh(),
                    Auth::user(),
                    'estado_operativo',
                    'radicado',
                    $data['justificacion']
                );
            }
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['expediente' => $e->getMessage()])->withInput();
        }

        return back()->with('success', 'Clasificación aplicada. SLA recalculado desde la recepción original.');
    }

    public function asignar(Request $request, Pqrsd $pqrsd): RedirectResponse
    {
        // Operación deshabilitada: bandeja compartida sin asignación obligatoria.
        // Conserva historial/servicio; no altera SLA ni columnas históricas.
        $this->authorize('assign', $pqrsd);

        abort(403, 'La asignación de responsable está deshabilitada. Use la Bandeja compartida.');
    }

    public function actuacion(Request $request, Pqrsd $pqrsd): RedirectResponse
    {
        $this->authorize('update', $pqrsd);

        // Operación retirada: el historial relevante se genera automáticamente
        // (clasificación, respuesta, comunicación, cierre). Conserva la ruta
        // para no romper enlaces antiguos y bloquear mutaciones ocultas.
        abort(403, 'El registro manual de gestiones está deshabilitado. Clasifique y responda la PQRSD; el sistema audita automáticamente.');
    }

    public function storeAdjunto(Request $request, Pqrsd $pqrsd): RedirectResponse
    {
        $this->authorize('update', $pqrsd);

        $request->validate([
            'adjuntos' => ['required', 'array', 'min:1', 'max:5'],
            'adjuntos.*' => ['file', 'max:10240'],
        ]);

        $this->adjuntoPqrsdService->guardarSoportesInternos(
            $pqrsd,
            $request->file('adjuntos', []),
            Auth::user()
        );

        ActuacionPqrsd::create([
            'pqrsd_id' => $pqrsd->id,
            'usuario_id' => Auth::id(),
            'tipo' => 'soporte_interno',
            'titulo' => 'Documento de soporte incorporado',
            'detalle' => 'Se agregaron soportes internos al expediente.',
            'es_interna' => true,
        ]);

        return back()->with('success', 'Soporte adjunto guardado de forma segura.');
    }

    public function cerrar(Request $request, Pqrsd $pqrsd): RedirectResponse
    {
        $this->authorize('close', $pqrsd);

        $data = $request->validate([
            'motivo_cierre' => ['required', 'string', 'min:10', 'max:500'],
            'expediente_version' => ['required', 'string', 'size:64', 'regex:/^[a-f0-9]{64}$/i'],
        ]);

        try {
            $this->vencimientoService->cerrar(
                $pqrsd,
                Auth::user(),
                $data['motivo_cierre'],
                $data['expediente_version'],
                versionObligatoria: true
            );
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors([
                'expediente' => $e->getMessage(),
                'motivo_cierre' => $e->getMessage(),
            ]);
        }

        return back()->with('success', 'PQRSD finalizada. La fecha de respuesta y el cumplimiento no se modificaron.');
    }

    public function responder(Request $request, Pqrsd $pqrsd): RedirectResponse
    {
        $this->authorize('update', $pqrsd);

        $data = $request->validate([
            'detalle_respuesta' => ['required', 'string', 'min:10'],
            'respuesta_estado_destino' => ['nullable', 'in:elaborada,aprobada,puesta_disposicion,enviada'],
            'marcar_envio_verificable' => ['sometimes', 'boolean'],
            'poner_disposicion_consulta' => ['sometimes', 'boolean'],
            'respuesta_canal_comunicacion' => ['nullable', 'string', 'max:80'],
            'respuesta_evidencia_comunicacion' => ['nullable', 'string', 'min:10', 'max:2000'],
            'expediente_version' => ['required', 'string', 'size:64', 'regex:/^[a-f0-9]{64}$/i'],
        ]);

        $estadoSolicitado = $data['respuesta_estado_destino'] ?? 'elaborada';
        $publicarPortal = $request->boolean('poner_disposicion_consulta') || $estadoSolicitado === 'puesta_disposicion';
        $registrarEnvio = $request->boolean('marcar_envio_verificable') || $estadoSolicitado === 'enviada';

        if ($registrarEnvio) {
            $request->validate([
                'respuesta_canal_comunicacion' => ['required', 'string', 'min:3', 'max:80'],
                'respuesta_evidencia_comunicacion' => ['required', 'string', 'min:10', 'max:2000'],
            ]);
            $data['respuesta_canal_comunicacion'] = $request->string('respuesta_canal_comunicacion')->toString();
            $data['respuesta_evidencia_comunicacion'] = $request->string('respuesta_evidencia_comunicacion')->toString();
        }

        try {
            \Illuminate\Support\Facades\DB::transaction(function () use ($request, $pqrsd, $data, $estadoSolicitado, $publicarPortal, $registrarEnvio) {
                $caso = \App\Models\Pqrsd::bloquearParaEscritura(
                    (int) $pqrsd->id,
                    $data['expediente_version'],
                    versionObligatoria: true
                );

                $ahora = now('America/Bogota');
                $estadoAnterior = $caso->respuesta_estado;
                $estadoFinal = $estadoSolicitado;
                if ($publicarPortal) {
                    $estadoFinal = 'puesta_disposicion';
                }
                if ($registrarEnvio) {
                    $estadoFinal = 'enviada';
                }

                $updates = [
                    'respuesta_consulta_texto' => $data['detalle_respuesta'],
                    'respuesta_estado' => $estadoFinal,
                    'respuesta_elaborada_en' => $caso->respuesta_elaborada_en ?? $ahora,
                ];

                $esComunicacion = $publicarPortal || $registrarEnvio
                    || in_array($estadoFinal, ['puesta_disposicion', 'enviada'], true);

                // Elaboración / aprobación: conserva borrador. No marca oportunidad ni «respondido».
                if (! $esComunicacion) {
                    $caso->update($updates);

                    \App\Models\ActuacionPqrsd::create([
                        'pqrsd_id' => $caso->id,
                        'usuario_id' => Auth::id(),
                        'tipo' => 'respuesta_fondo',
                        'titulo' => 'Respuesta elaborada',
                        'detalle' => $data['detalle_respuesta'],
                        'es_interna' => false,
                    ]);

                    \App\Models\HistorialEstadoPqrsd::create([
                        'pqrsd_id' => $caso->id,
                        'usuario_id' => Auth::id(),
                        'campo' => 'respuesta_estado',
                        'valor_anterior' => $estadoAnterior,
                        'valor_nuevo' => $estadoFinal,
                        'motivo' => 'Respuesta elaborada (borrador). No constituye comunicación oficial.',
                    ]);

                    $this->pqrsdService->auditar($caso->fresh(), Auth::user(), 'elaborar_respuesta', [
                        'respuesta_estado' => $estadoAnterior,
                    ], [
                        'respuesta_estado' => $estadoFinal,
                        'respuesta_elaborada_en' => optional($caso->fresh()->respuesta_elaborada_en)->toDateTimeString(),
                    ]);

                    return;
                }

                // Comunicación válida: fecha_respuesta = reloj del servidor (no editable por auxiliar).
                if (! $caso->fecha_respuesta) {
                    $this->vencimientoService->registrarRespuesta(
                        $caso,
                        Auth::user(),
                        $ahora,
                        null,
                        yaBloqueadoEnTransaccion: true
                    );
                    $caso->refresh();
                }

                if ($publicarPortal || $estadoFinal === 'puesta_disposicion') {
                    $updates['respuesta_puesta_disposicion_en'] = $caso->respuesta_puesta_disposicion_en ?? $ahora;
                }

                if ($registrarEnvio || $estadoFinal === 'enviada') {
                    $updates['respuesta_enviada_en'] = $caso->respuesta_enviada_en ?? $ahora;
                    $updates['respuesta_canal_comunicacion'] = $data['respuesta_canal_comunicacion']
                        ?? $caso->respuesta_canal_comunicacion;
                    $updates['respuesta_evidencia_comunicacion'] = $data['respuesta_evidencia_comunicacion']
                        ?? $caso->respuesta_evidencia_comunicacion;
                }

                $caso->update($updates);

                $tituloActuacion = $estadoFinal === 'enviada'
                    ? 'Evidencia de comunicación registrada'
                    : 'Respuesta disponible en consulta segura';

                \App\Models\ActuacionPqrsd::create([
                    'pqrsd_id' => $caso->id,
                    'usuario_id' => Auth::id(),
                    'tipo' => 'respuesta_fondo',
                    'titulo' => $tituloActuacion,
                    'detalle' => $data['detalle_respuesta']
                        .($estadoFinal === 'enviada'
                            ? ' | Canal: '.($updates['respuesta_canal_comunicacion'] ?? '—')
                              .' | Evidencia: '.($updates['respuesta_evidencia_comunicacion'] ?? '—')
                            : ''),
                    'es_interna' => false,
                ]);

                \App\Models\HistorialEstadoPqrsd::create([
                    'pqrsd_id' => $caso->id,
                    'usuario_id' => Auth::id(),
                    'campo' => 'respuesta_estado',
                    'valor_anterior' => $estadoAnterior,
                    'valor_nuevo' => $estadoFinal,
                    'motivo' => $estadoFinal === 'enviada'
                        ? 'Comunicación por canal verificable'
                        : 'Puesta a disposición en consulta segura',
                ]);
            });
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['expediente' => $e->getMessage()])->withInput();
        }

        $estado = $pqrsd->fresh()->respuesta_estado;
        $mensaje = match ($estado) {
            'elaborada', 'aprobada' => 'Respuesta elaborada guardada. Aún no cuenta como comunicación oficial ni para el indicador de oportunidad.',
            'puesta_disposicion' => 'Respuesta disponible en consulta segura. No confirma que el ciudadano la haya leído.',
            'enviada' => 'Evidencia de comunicación registrada. No afirma acuse de recibo ni envío por Gmail.',
            default => 'Respuesta de PQRS actualizada (estado: '.$estado.').',
        };

        return back()->with('success', $mensaje);
    }

    private function formData(): array
    {
        return [
            'canales' => CanalIngreso::where('activo', true)->orderBy('nombre')->get(),
            'tipos' => TipoPqrsd::where('activo', true)->orderBy('orden')->get(),
            'motivos' => MotivoPqrsd::where('activo', true)->orderBy('nombre')->get(),
            'sedes' => Sede::where('activo', true)->orderBy('nombre')->get(),
            'aseguradoras' => Aseguradora::where('activo', true)->orderBy('nombre')->get(),
            'responsables' => User::where('activo', true)->orderBy('name')->get(),
        ];
    }
}
