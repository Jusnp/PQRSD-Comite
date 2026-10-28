<?php

namespace App\Http\Controllers\Publico;

use App\Http\Controllers\Controller;
use App\Models\Aseguradora;
use App\Models\CanalIngreso;
use App\Models\MotivoPqrsd;
use App\Models\Pqrsd;
use App\Models\Sede;
use App\Models\TipoPqrsd;
use App\Models\User;
use App\Services\AdjuntoPqrsdService;
use App\Services\ConflictoIdempotenciaPublica;
use App\Services\IdempotenciaRadicacionPublica;
use App\Services\EncuestaSatisfaccionService;
use App\Services\PqrsdService;
use App\Support\EstadoPublicoPqrsd;
use App\Support\TelefonoColombia;
use App\Support\PublicUrl;
use App\Support\QrPngGenerator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use SimpleSoftwareIO\QrCode\Facades\QrCode;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class FormularioPublicoController extends Controller
{
    public function __construct(
        private readonly PqrsdService $pqrsdService,
        private readonly AdjuntoPqrsdService $adjuntoService,
        private readonly IdempotenciaRadicacionPublica $idempotencia,
    ) {}

    public function create(Request $request): View
    {
        return view('publico.formulario', [
            'tipos' => TipoPqrsd::where('activo', true)->orderBy('orden')->get(),
            'motivos' => MotivoPqrsd::with('subcategoriaSugerida.categoria')->where('activo', true)->orderBy('nombre')->get(),
            'sedes' => Sede::where('activo', true)->orderBy('nombre')->get(),
            'aseguradoras' => Aseguradora::where('activo', true)->orderBy('nombre')->get(),
            'viaQr' => $request->boolean('qr'),
            'adjuntosConfig' => config('pqrsd_publico.adjuntos'),
            'mediosRespuesta' => config('pqrsd_publico.medios_respuesta', []),
            'tiposDocumento' => config('pqrsd_publico.tipos_documento', []),
            'anonimoTiposPrioritarios' => config('pqrsd_publico.anonimo_tipos_prioritarios', ['QUEJA', 'DENUNCIA']),
            'idempotencyKey' => (string) Str::uuid(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $key = 'publico-pqrsd:'.$request->ip();
        if (RateLimiter::tooManyAttempts($key, 8)) {
            return back()->withErrors(['descripcion' => 'Demasiados intentos. Espere unos minutos e intente de nuevo.']);
        }
        RateLimiter::hit($key, 600);

        if (filled($request->input('website'))) {
            return redirect()->route('publico.formulario');
        }

        $maxFiles = (int) config('pqrsd_publico.adjuntos.max_files', 5);
        $maxKb = (int) config('pqrsd_publico.adjuntos.max_kb', 10240);
        $medios = array_keys(config('pqrsd_publico.medios_respuesta', []));
        $tiposDoc = array_keys(config('pqrsd_publico.tipos_documento', []));

        $esAnonimo = $request->boolean('es_anonimo');
        $noClasifica = $request->boolean('ciudadano_no_clasifica');
        if ($noClasifica) {
            $request->merge(['tipo_pqrsd_id' => null, 'motivo_pqrsd_id' => null]);
        }
        if ($request->input('motivo_pqrsd_id') === '') {
            $request->merge(['motivo_pqrsd_id' => null]);
        }
        $quien = (string) $request->input('quien_radica', '');
        $esPaciente = $quien === 'paciente';
        $esTercero = $quien === 'tercero';

        if ($esAnonimo) {
            $request->merge([
                'solicitante_nombre' => null,
                'solicitante_tipo_documento' => null,
                'solicitante_documento' => null,
                'solicitante_telefono' => null,
            ]);
        }
        if ($esPaciente) {
            $request->merge([
                'paciente_nombre' => null,
                'paciente_tipo_documento' => null,
                'paciente_documento' => null,
                'relacion_paciente' => null,
            ]);
        }
        if ($request->input('solicitante_telefono') === '') {
            $request->merge(['solicitante_telefono' => null]);
        }

        $data = $request->validate([
            'idempotency_key' => ['required', 'uuid'],
            'tipo_pqrsd_id' => [$noClasifica ? 'nullable' : 'required', 'exists:tipos_pqrsd,id'],
            'motivo_pqrsd_id' => ['nullable', 'exists:motivos_pqrsd,id'],
            'ciudadano_no_clasifica' => ['sometimes', 'boolean'],
            'es_anonimo' => ['sometimes', 'boolean'],
            'quien_radica' => ['required', Rule::in(['paciente', 'tercero'])],
            'medio_respuesta' => ['required', 'in:'.implode(',', $medios)],
            'solicitante_nombre' => [$esAnonimo ? 'nullable' : 'required', 'string', 'max:255'],
            'solicitante_tipo_documento' => [$esAnonimo ? 'nullable' : 'required', Rule::in($tiposDoc)],
            'solicitante_documento' => [$esAnonimo ? 'nullable' : 'required', 'string', 'max:30'],
            'solicitante_email' => ['nullable', 'email', 'max:255'],
            'solicitante_telefono' => [$esAnonimo ? 'nullable' : 'required', 'string', 'max:30'],
            'solicitante_direccion' => ['nullable', 'string', 'max:500'],
            'consentimiento_comunicacion_email' => ['sometimes', 'boolean'],
            'relacion_paciente' => [$esTercero && ! $esAnonimo ? 'required' : 'nullable', 'string', 'max:255'],
            'paciente_nombre' => [$esTercero ? 'required' : 'nullable', 'string', 'max:255'],
            'paciente_tipo_documento' => [$esTercero ? 'required' : 'nullable', Rule::in($tiposDoc)],
            'paciente_documento' => [$esTercero ? 'required' : 'nullable', 'string', 'max:30'],
            'paciente_fecha_nacimiento' => ['nullable', 'date', 'before_or_equal:today'],
            'aseguradora_id' => ['nullable', 'exists:aseguradoras,id'],
            'sede_id' => ['nullable', 'exists:sedes,id'],
            'servicio_area' => ['nullable', 'string', 'max:255'],
            'asunto' => ['required', 'string', 'min:5', 'max:255'],
            'descripcion' => ['required', 'string', 'min:20', 'max:5000'],
            'fecha_hechos' => ['nullable', 'date', 'before_or_equal:today'],
            'acepto_tratamiento_datos' => [($this->politicaPublicada() && ! $esAnonimo) ? 'accepted' : 'nullable'],
            'adjuntos' => ['nullable', 'array', 'max:'.$maxFiles],
            'adjuntos.*' => ['file', 'max:'.$maxKb],
        ], [
            'acepto_tratamiento_datos.accepted' => 'Debe aceptar el tratamiento de datos personales (Ley 1581 de 2012).',
            'idempotency_key.required' => 'Recargue el formulario e intente de nuevo.',
            'medio_respuesta.required' => 'Seleccione cómo desea recibir o consultar la respuesta.',
            'medio_respuesta.in' => 'Seleccione correo electrónico, teléfono o consulta segura en el portal.',
            'quien_radica.required' => 'Indique si usted es el paciente o presenta la solicitud en nombre de otra persona.',
            'solicitante_telefono.required' => 'El teléfono es obligatorio cuando la solicitud no es anónima.',
            'asunto.required' => 'Indique el asunto de la manifestación.',
            'descripcion.min' => 'La descripción debe tener al menos :min caracteres.',
        ]);

        if (! $esAnonimo && ! TelefonoColombia::esValido($data['solicitante_telefono'] ?? null)) {
            return back()->withInput()->withErrors([
                'solicitante_telefono' => 'Ingrese un teléfono móvil o fijo de Colombia. Ejemplo: 3001234567 o 6041234567.',
            ]);
        }

        $medio = $data['medio_respuesta'];

        if ($medio === 'email' && blank($data['solicitante_email'] ?? null)) {
            return back()->withInput()->withErrors([
                'solicitante_email' => 'Indique el correo electrónico para recibir la respuesta.',
            ]);
        }

        if ($medio === 'telefono' && $esAnonimo) {
            return back()->withInput()->withErrors([
                'medio_respuesta' => 'Una radicación anónima no guarda teléfono, así que no puede usar ese medio de respuesta.',
            ]);
        }

        if ($medio === 'email' && ! $request->boolean('consentimiento_comunicacion_email')) {
            return back()->withInput()->withErrors([
                'consentimiento_comunicacion_email' => 'Confirme la autorización para utilizar ese correo.',
            ]);
        }

        // Evitar HTTP manipulado: anónimo no puede forzar identidad; tercero requiere paciente.
        if ($esAnonimo && (filled($request->input('solicitante_nombre')) || filled($request->input('solicitante_documento')))) {
            // Se ignoran en persistencia; no error agresivo para no filtrar UX de alternancia.
        }
        if (! $esPaciente && ! $esTercero) {
            return back()->withInput()->withErrors([
                'quien_radica' => 'Indique si usted es el paciente o presenta la solicitud en nombre de otra persona.',
            ]);
        }

        $tipo = $noClasifica ? null : TipoPqrsd::query()->findOrFail($data['tipo_pqrsd_id']);
        $prioritarios = config('pqrsd_publico.anonimo_tipos_prioritarios', ['QUEJA', 'DENUNCIA']);
        $anonimoPendienteJuridica = $esAnonimo && $tipo && ! in_array($tipo->codigo, $prioritarios, true);

        $canalCodigo = $request->boolean('via_qr') ? 'QR' : 'WEB';
        $canal = CanalIngreso::where('codigo', $canalCodigo)->firstOrFail();

        $sistema = User::where('email', 'admin@menteplena.test')->first()
            ?? User::role('auxiliar_pqrsd')->first()
            ?? User::query()->firstOrFail();

        $tokenPlano = Str::random(40);
        $files = array_values(array_filter(
            $request->file('adjuntos', []) ?: [],
            fn ($f) => $f !== null
        ));

        $descripcion = 'Asunto: '.$data['asunto']."\n\n".$data['descripcion'];
        if (filled($data['fecha_hechos'] ?? null)) {
            $descripcion .= "\n\nFecha de los hechos (declarada): ".$data['fecha_hechos'];
        }
        if ($esTercero && filled($data['relacion_paciente'] ?? null)) {
            $descripcion .= "\n\nRelación con el paciente: ".$data['relacion_paciente'];
        }
        $descripcion .= "\n\nQuién presenta: ".($esPaciente ? 'El paciente' : 'Tercero / representante');

        $nombre = $esAnonimo ? null : ($data['solicitante_nombre'] ?? null);
        $tipoDoc = $esAnonimo ? null : ($data['solicitante_tipo_documento'] ?? null);
        $documento = $esAnonimo ? null : ($data['solicitante_documento'] ?? null);
        $email = match ($medio) {
            'email' => $data['solicitante_email'] ?? null,
            default => $esAnonimo ? null : ($data['solicitante_email'] ?? null),
        };
        $direccion = null;
        $telefono = $esAnonimo ? null : ($data['solicitante_telefono'] ?? null);

        if ($esPaciente) {
            $pacienteNombre = $esAnonimo ? null : $nombre;
            $pacienteTipoDoc = $esAnonimo ? null : $tipoDoc;
            $pacienteDoc = $esAnonimo ? null : $documento;
            $representante = null;
        } else {
            $pacienteNombre = $data['paciente_nombre'] ?? null;
            $pacienteTipoDoc = $data['paciente_tipo_documento'] ?? null;
            $pacienteDoc = $data['paciente_documento'] ?? null;
            $representante = $esAnonimo ? ($data['relacion_paciente'] ?? null) : ($nombre ?: ($data['relacion_paciente'] ?? null));
        }

        $huella = $this->idempotencia->huella([
            'tipo_pqrsd_id' => $noClasifica ? null : (int) $data['tipo_pqrsd_id'],
            'motivo_pqrsd_id' => $data['motivo_pqrsd_id'] ?? null,
            'ciudadano_no_clasifica' => $noClasifica,
            'es_anonimo' => $esAnonimo,
            'quien_radica' => $data['quien_radica'],
            'medio' => $medio,
            'nombre' => $nombre,
            'tipo_documento' => $tipoDoc,
            'documento' => $documento,
            'email' => $email,
            'telefono' => $telefono,
            'direccion' => $direccion,
            'paciente_nombre' => $pacienteNombre,
            'paciente_tipo_documento' => $pacienteTipoDoc,
            'paciente_documento' => $pacienteDoc,
            'paciente_fecha_nacimiento' => $data['paciente_fecha_nacimiento'] ?? null,
            'representante' => $representante,
            'aseguradora_id' => $data['aseguradora_id'] ?? null,
            'sede_id' => $data['sede_id'] ?? null,
            'servicio_area' => $data['servicio_area'] ?? null,
            'descripcion' => $descripcion,
            'canal' => $canalCodigo,
            'adjuntos' => array_map(function ($archivo) {
                $ruta = $archivo->getRealPath();
                $contenido = $ruta ? hash_file('sha256', $ruta) : '';

                return $contenido.'|'.$archivo->getSize().'|'.$archivo->getClientOriginalName();
            }, $files),
        ]);

        $idemKey = 'publico-idem:'.$data['idempotency_key'];
        $reintento = $this->confirmacionYaRadicada($idemKey, $huella);
        if ($reintento) {
            return $reintento;
        }

        // 90 s: cubre la carga de adjuntos y expira si el proceso muere.
        // El almacén configurado (database) comparte el bloqueo entre trabajadores.
        $bloqueo = Cache::lock('publico-idem-lock:'.$data['idempotency_key'], 90);
        if (! $bloqueo->get()) {
            return back()->withInput()->withErrors([
                'descripcion' => 'Ya hay una radicación en curso con este formulario. Espere a que termine y no lo envíe otra vez.',
            ]);
        }

        try {
            $reintento = $this->confirmacionYaRadicada($idemKey, $huella);
            if ($reintento) {
                return $reintento;
            }

            try {
                $resultado = $this->idempotencia->resolver(
                    $data['idempotency_key'],
                    $huella,
                    $tokenPlano,
                    function () use (
                        $data, $canal, $sistema, $tokenPlano, $files, $descripcion,
                        $esAnonimo, $anonimoPendienteJuridica, $noClasifica, $medio, $nombre, $tipoDoc, $documento, $email, $direccion, $telefono,
                        $pacienteNombre, $pacienteTipoDoc, $pacienteDoc, $representante, $canalCodigo
                    ) {
                        $caso = $this->pqrsdService->radicarManual([
                            'canal_ingreso_id' => $canal->id,
                            'tipo_pqrsd_id' => $noClasifica ? null : $data['tipo_pqrsd_id'],
                            'motivo_pqrsd_id' => $noClasifica ? null : ($data['motivo_pqrsd_id'] ?? null),
                            'ciudadano_no_clasifica' => $noClasifica,
                            'alerta_vital_preventiva' => false,
                            'nivel_riesgo' => 'pendiente',
                            'es_anonimo' => $esAnonimo,
                            'solicitante_nombre' => $nombre,
                            'solicitante_tipo_documento' => $tipoDoc,
                            'solicitante_documento' => $documento,
                            'solicitante_email' => $email,
                            'solicitante_telefono' => $telefono,
                            'paciente_nombre' => $pacienteNombre,
                            'paciente_tipo_documento' => $pacienteTipoDoc,
                            'paciente_documento' => $pacienteDoc,
                            'paciente_fecha_nacimiento' => $data['paciente_fecha_nacimiento'] ?? null,
                            'representante_nombre' => $representante,
                            'aseguradora_id' => $data['aseguradora_id'] ?? null,
                            'sede_id' => $data['sede_id'] ?? null,
                            'servicio_area' => $data['servicio_area'] ?? null,
                            'descripcion' => $descripcion,
                        ], $sistema);

                        $registraConsentimiento = $this->politicaPublicada() && ! $esAnonimo;
                        $caso->update([
                            'consulta_token_hash' => hash('sha256', $tokenPlano),
                            'acepto_tratamiento_datos' => $registraConsentimiento,
                            'tratamiento_aceptado_en' => $registraConsentimiento ? now('America/Bogota') : null,
                            'tratamiento_politica_version' => $registraConsentimiento
                                ? $this->versionConsentimiento()
                                : null,
                            'consentimiento_comunicacion_email' => $medio === 'email' && request()->boolean('consentimiento_comunicacion_email'),
                            'origen_publico' => $canalCodigo === 'QR' ? 'qr' : 'web',
                            'medio_respuesta' => $medio,
                            'solicitante_direccion' => $direccion,
                            'anonimo_pendiente_validacion_juridica' => $anonimoPendienteJuridica,
                            'ciudadano_no_clasifica' => $noClasifica,
                            'alerta_vital_preventiva' => false,
                        ]);

                        if ($files !== []) {
                            $this->adjuntoService->guardarSoportesPublicos($caso, $files, $sistema);
                        }

                        return $caso->fresh();
                    }
                );
            } catch (\Illuminate\Validation\ValidationException $e) {
                return back()->withInput()->withErrors($e->errors());
            } catch (ConflictoIdempotenciaPublica) {
                return back()->withInput()->withErrors([
                    'idempotency_key' => 'Este formulario ya se utilizó con otros datos. Recargue la página para iniciar una solicitud nueva.',
                ]);
            }

            $caso = $resultado['caso'];
            $tokenConfirmacion = $resultado['token'];

            Cache::put($idemKey, [
                'radicado' => $caso->radicado,
                'token' => $tokenConfirmacion,
                'huella' => $huella,
                'pqrsd_id' => $caso->id,
            ], now()->addMinutes(30));

            return redirect()->route('publico.confirmacion')
                ->with('publico_confirmacion', [
                    'radicado' => $caso->radicado,
                    'token' => $tokenConfirmacion,
                ]);
        } finally {
            $bloqueo->release();
        }
    }

    private function confirmacionYaRadicada(string $idemKey, string $huella): ?RedirectResponse
    {
        $prev = Cache::get($idemKey);
        if (! is_array($prev) || ! isset($prev['radicado'], $prev['token'], $prev['huella'])) {
            return null;
        }

        if (! hash_equals((string) $prev['huella'], $huella)) {
            return back()->withInput()->withErrors([
                'idempotency_key' => 'Este formulario ya se utilizó con otros datos. Recargue la página para iniciar una solicitud nueva.',
            ]);
        }

        return redirect()->route('publico.confirmacion')
            ->with('publico_confirmacion', [
                'radicado' => $prev['radicado'],
                'token' => $prev['token'],
            ]);
    }

    public function confirmacion(Request $request): View|RedirectResponse
    {
        $payload = $request->session()->get('publico_confirmacion');
        if (! is_array($payload) || blank($payload['radicado'] ?? null) || blank($payload['token'] ?? null)) {
            return redirect()->route('publico.formulario')
                ->withErrors(['descripcion' => 'La confirmación ya no está disponible. Si radicó, consulte el estado con su número de radicado.']);
        }

        $radicado = (string) $payload['radicado'];
        $token = (string) $payload['token'];
        $caso = Pqrsd::query()
            ->with('tipo')
            ->where('radicado', strtoupper(trim($radicado)))
            ->first();

        return view('publico.confirmacion', [
            'radicado' => $radicado,
            'token' => $token,
            'caso' => $caso,
            'requiereCodigoPrivado' => ($caso?->medio_respuesta === 'consulta_segura'),
        ]);
    }

    /**
     * Consulta pública de estado general: solo número de radicado.
     */
    public function consulta(Request $request): View|RedirectResponse
    {
        if ($request->isMethod('get')) {
            if ($request->filled('radicado') || $request->filled('token')) {
                return redirect()->route('publico.consulta')
                    ->withErrors(['radicado' => 'Por seguridad, use el formulario de consulta (no envíe datos en la URL).']);
            }

            return view('publico.consulta');
        }

        $data = $request->validate([
            'radicado' => ['required', 'string', 'max:30', 'regex:/^PQRSD-\d{4}-\d{4,}$/i'],
        ], [
            'radicado.required' => 'Ingrese el número de radicado.',
            'radicado.regex' => 'El número de radicado no tiene un formato válido.',
        ]);

        $max = (int) config('pqrsd_publico.consulta_estado.max_intentos', 12);
        $ventana = (int) config('pqrsd_publico.consulta_estado.ventana_segundos', 300);
        $key = 'consulta-estado:'.$request->ip();
        if (RateLimiter::tooManyAttempts($key, $max)) {
            return back()->withErrors(['radicado' => 'Demasiados intentos de consulta. Espere unos minutos e intente de nuevo.']);
        }
        RateLimiter::hit($key, $ventana);

        $radicado = strtoupper(trim($data['radicado']));
        $caso = Pqrsd::query()->where('radicado', $radicado)->first();

        if (! $caso) {
            return back()->withErrors([
                'radicado' => 'No encontramos una PQRSD con ese número de radicado. Verifique e intente de nuevo.',
            ])->withInput($request->only('radicado'));
        }

        return view('publico.consulta-estado', [
            'radicado' => $caso->radicado,
            'estadoPublico' => EstadoPublicoPqrsd::etiqueta($caso),
            'mensaje' => EstadoPublicoPqrsd::mensaje($caso),
            'medioEtiqueta' => \App\Support\MedioRespuesta::etiqueta($caso->medio_respuesta),
        ]);
    }

    /**
     * Consulta segura (privada): radicado + código. Acceso al contenido de respuesta.
     */
    public function consultaSegura(Request $request): View|RedirectResponse
    {
        if ($request->isMethod('get')) {
            if ($request->filled('radicado') || $request->filled('token')) {
                return redirect()->route('publico.consulta.segura')
                    ->withErrors(['radicado' => 'Por seguridad, use el formulario (el código no debe enviarse en la URL).']);
            }

            return view('publico.consulta-segura');
        }

        $data = $request->validate([
            'radicado' => ['required', 'string', 'max:30'],
            'token' => ['required', 'string', 'min:20', 'max:80'],
        ], [
            'radicado.required' => 'Ingrese el número de radicado.',
            'token.required' => 'Ingrese el código de consulta privado.',
            'token.min' => 'El código de consulta no es válido.',
        ]);

        $key = 'consulta-segura:'.$request->ip();
        if (RateLimiter::tooManyAttempts($key, 20)) {
            return back()->withErrors(['radicado' => 'Demasiados intentos de consulta. Espere unos minutos.']);
        }
        RateLimiter::hit($key, 300);

        $caso = Pqrsd::query()
            ->where('radicado', strtoupper(trim($data['radicado'])))
            ->where('consulta_token_hash', hash('sha256', $data['token']))
            ->first();

        if (! $caso) {
            return back()->withErrors([
                'radicado' => 'No fue posible validar la consulta con los datos suministrados.',
            ])->withInput($request->only('radicado'));
        }

        if ($caso->respuestaDisponibleParaConsulta() && $caso->respuesta_consultada_en === null) {
            $caso->update([
                'respuesta_estado' => 'consultada',
                'respuesta_consultada_en' => now('America/Bogota'),
            ]);
            $caso->refresh();
        }

        return $this->vistaConsultaAutorizada($caso, $data['token']);
    }

    public function encuesta(Request $request, EncuestaSatisfaccionService $encuestas): View|RedirectResponse
    {
        $data = $request->validate([
            'radicado' => ['required', 'string', 'max:30'],
            'token' => ['required', 'string', 'min:20', 'max:80'],
            'puntuacion' => ['required', 'integer', 'between:1,5'],
            'atendida' => ['required', Rule::in(['si', 'parcialmente', 'no'])],
            'comentario' => ['nullable', 'string', 'max:1000'],
        ]);

        $caso = Pqrsd::query()
            ->where('radicado', strtoupper(trim($data['radicado'])))
            ->where('consulta_token_hash', hash('sha256', $data['token']))
            ->first();

        if (! $caso) {
            return back()->withErrors([
                'radicado' => 'No fue posible validar la consulta con los datos suministrados.',
            ]);
        }

        try {
            $encuestas->registrar($caso, $data);
        } catch (\Illuminate\Validation\ValidationException $e) {
            $bolsa = new \Illuminate\Support\ViewErrorBag;
            $bolsa->put('default', new \Illuminate\Support\MessageBag($e->errors()));

            return $this->vistaConsultaAutorizada($caso, $data['token'])->with('errors', $bolsa);
        }

        session()->now('success', 'Gracias. Su valoración quedó registrada y no reabre el expediente.');

        return $this->vistaConsultaAutorizada($caso->fresh(), $data['token']);
    }

    private function vistaConsultaAutorizada(Pqrsd $caso, string $token): View
    {
        return view('publico.consulta-resultado', [
            'caso' => $caso->load(['tipo', 'sede', 'canal', 'encuesta']),
            'respuestaPublica' => $caso->respuestaDisponibleParaConsulta()
                ? $caso->respuesta_consulta_texto
                : null,
            'tokenConsulta' => $token,
        ]);
    }

    public function qr(): View
    {
        $url = PublicUrl::formulario(viaQr: true);
        $distributable = PublicUrl::isDistributable($url);
        $this->ensureQrFiles($url);

        $version = substr(sha1($url), 0, 12);

        return view('publico.qr', [
            'url' => $url,
            'distributable' => $distributable,
            'urlSource' => PublicUrl::source(),
            'qrSvgAsset' => asset('qrs/formulario-pqrsd.svg').'?v='.$version,
            'qrPngAsset' => asset('qrs/formulario-pqrsd.png').'?v='.$version,
        ]);
    }

    public function qrDownload(Request $request): BinaryFileResponse
    {
        $format = strtolower($request->string('format', 'svg')->toString());
        if (! in_array($format, ['svg', 'png'], true)) {
            abort(404);
        }

        $url = PublicUrl::formulario(viaQr: true);
        $this->ensureQrFiles($url);

        if ($format === 'svg') {
            $path = public_path('qrs/formulario-pqrsd.svg');

            return response()->file($path, [
                'Content-Type' => 'image/svg+xml',
                'Content-Disposition' => 'attachment; filename="qr-pqrsd-comite.svg"',
            ]);
        }

        $pngPath = public_path('qrs/formulario-pqrsd.png');
        if (! is_file($pngPath)) {
            abort(500, 'No fue posible generar el PNG del código QR.');
        }

        return response()->file($pngPath, [
            'Content-Type' => 'image/png',
            'Content-Disposition' => 'attachment; filename="qr-pqrsd-comite.png"',
        ]);
    }

    private function ensureQrFiles(string $url): void
    {
        $dir = public_path('qrs');
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $marker = $dir.DIRECTORY_SEPARATOR.'.url-hash';
        $target = $dir.DIRECTORY_SEPARATOR.'formulario-pqrsd.url.txt';
        $svgPath = $dir.DIRECTORY_SEPARATOR.'formulario-pqrsd.svg';
        $pngPath = $dir.DIRECTORY_SEPARATOR.'formulario-pqrsd.png';
        $hash = sha1($url);
        $needsRegen = ! is_file($marker) || trim((string) file_get_contents($marker)) !== $hash
            || ! is_file($target) || trim((string) file_get_contents($target)) !== $url
            || ! is_file($svgPath)
            || ! is_file($pngPath);

        if (! $needsRegen) {
            return;
        }

        foreach ([$svgPath, $pngPath, $marker, $target] as $old) {
            if (is_file($old)) {
                @unlink($old);
            }
        }

        file_put_contents(
            $svgPath,
            QrCode::format('svg')->size(320)->margin(1)->generate($url)
        );

        file_put_contents($pngPath, QrPngGenerator::generate($url, 320));
        file_put_contents($target, $url);
        file_put_contents($marker, $hash);
    }

    private function politicaPublicada(): bool
    {
        return trim((string) config('identidad.politica_tratamiento_url')) !== ''
            && trim((string) config('identidad.politica_tratamiento_version')) !== '';
    }

    private function versionConsentimiento(): string
    {
        $version = trim((string) config('identidad.politica_tratamiento_version'));
        $codigo = trim((string) config('identidad.politica_tratamiento_codigo'));

        return $codigo !== '' ? $codigo.' V'.$version : $version;
    }
}
