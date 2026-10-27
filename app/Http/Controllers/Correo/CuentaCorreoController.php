<?php

namespace App\Http\Controllers\Correo;

use App\Http\Controllers\Controller;
use App\Mail\MailProviderManager;
use App\Models\Aseguradora;
use App\Models\CuentaCorreo;
use App\Models\Sede;
use App\Services\Correo\CuentaCorreoInventarioService;
use App\Services\Correo\EmailSyncService;
use App\Services\Gmail\GmailOAuthService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CuentaCorreoController extends Controller
{
    public function index(CuentaCorreoInventarioService $inventario): View
    {
        $items = CuentaCorreo::with(['aseguradora', 'sede'])
            ->orderByDesc('es_buzon_central')
            ->orderBy('direccion')
            ->paginate(20);

        $pendientes = $inventario->resumenPendientes()->where('pendiente', true)->count();

        return view('correo.cuentas.index', compact('items', 'pendientes'));
    }

    public function exportInventario(CuentaCorreoInventarioService $inventario): StreamedResponse
    {
        $csv = $inventario->exportCsv();
        $name = 'inventario-buzones-pqrsd-'.now('America/Bogota')->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($csv) {
            echo $csv;
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function importInventario(Request $request, CuentaCorreoInventarioService $inventario): RedirectResponse
    {
        $request->validate([
            'inventario_csv' => ['required', 'file', 'mimes:csv,txt', 'max:2048'],
        ]);

        try {
            $r = $inventario->importCsv($request->file('inventario_csv'));
        } catch (\Throwable $e) {
            return back()->withErrors(['inventario_csv' => $e->getMessage()]);
        }

        $msg = "Inventario: {$r['actualizadas']} actualizadas, {$r['omitidas']} omitidas.";
        if ($r['errores']) {
            $msg .= ' Detalles: '.implode(' | ', array_slice($r['errores'], 0, 5));
        }

        return back()->with('success', $msg);
    }

    public function revocarOauth(CuentaCorreo $cuentaCorreo, GmailOAuthService $oauth): RedirectResponse
    {
        $oauth->revoke($cuentaCorreo);

        return back()->with('success', 'OAuth revocado localmente para '.$cuentaCorreo->direccion);
    }

    public function create(): View
    {
        return view('correo.cuentas.form', [
            'item' => new CuentaCorreo([
                'activo' => true,
                'tipo_integracion' => 'mock',
                'tipo_buzon' => 'desconocido',
                'estado_autorizacion' => 'pendiente',
                'estado_conexion' => 'desconocido',
            ]),
            'aseguradoras' => Aseguradora::orderBy('nombre')->get(),
            'sedes' => Sede::orderBy('nombre')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        CuentaCorreo::create($this->validated($request));

        return redirect()->route('cuentas-correo.index')->with('success', 'Cuenta de correo registrada.');
    }

    public function edit(CuentaCorreo $cuentaCorreo): View
    {
        return view('correo.cuentas.form', [
            'item' => $cuentaCorreo,
            'aseguradoras' => Aseguradora::orderBy('nombre')->get(),
            'sedes' => Sede::orderBy('nombre')->get(),
        ]);
    }

    public function update(Request $request, CuentaCorreo $cuentaCorreo): RedirectResponse
    {
        $cuentaCorreo->update($this->validated($request, $cuentaCorreo->id));

        return redirect()->route('cuentas-correo.index')->with('success', 'Cuenta actualizada.');
    }

    public function probar(CuentaCorreo $cuentaCorreo, MailProviderManager $manager): RedirectResponse
    {
        $resultado = $manager->for($cuentaCorreo)->testConnection($cuentaCorreo);
        $cuentaCorreo->update([
            'estado_conexion' => $resultado['ok'] ? 'ok' : 'error',
            'ultimo_error' => $resultado['ok'] ? null : $resultado['detalle'],
        ]);

        return back()->with(
            $resultado['ok'] ? 'success' : 'error',
            $resultado['detalle'] ?? ($resultado['ok'] ? 'Conexión OK' : 'Fallo de conexión')
        );
    }

    public function sincronizar(CuentaCorreo $cuentaCorreo, EmailSyncService $sync): RedirectResponse
    {
        $r = $sync->syncCuenta($cuentaCorreo);

        return back()->with('success', sprintf(
            'Sincronización %s: %d nuevos, %d duplicados, %d errores.',
            $cuentaCorreo->direccion,
            $r['nuevos'],
            $r['duplicados'],
            $r['errores']
        ));
    }

    private function validated(Request $request, ?int $id = null): array
    {
        $data = $request->validate([
            'direccion' => ['required', 'email', 'max:255', 'unique:cuentas_correo,direccion,'.($id ?? 'NULL')],
            'descripcion' => ['nullable', 'string', 'max:255'],
            'aseguradora_id' => ['nullable', 'exists:aseguradoras,id'],
            'sede_id' => ['nullable', 'exists:sedes,id'],
            'area_responsable' => ['nullable', 'string', 'max:255'],
            'responsable_nombre' => ['nullable', 'string', 'max:255'],
            'tipo_buzon' => ['required', 'in:individual,alias,grupo,reenvio,desconocido,otro'],
            'buzon_canonico' => ['nullable', 'email', 'max:255'],
            'estado_autorizacion' => ['required', 'in:pendiente,autorizado,rechazado,no_aplica'],
            'observaciones_inventario' => ['nullable', 'string', 'max:2000'],
            'tipo_integracion' => ['required', 'in:mock,imap,microsoft_graph,google_api,central_forward'],
            'credential_ref' => ['nullable', 'string', 'max:100', 'regex:/^[A-Z][A-Z0-9_]*$/'],
            'host' => ['nullable', 'string', 'max:255'],
            'puerto' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'cifrado' => ['nullable', 'in:ssl,tls,starttls'],
            'activo' => ['sometimes', 'boolean'],
            'es_buzon_central' => ['sometimes', 'boolean'],
        ]);

        $data['activo'] = $request->boolean('activo');
        $data['es_buzon_central'] = $request->boolean('es_buzon_central');
        $data['fecha_ultima_validacion_inventario'] = now('America/Bogota');

        // Nunca aceptar password en el request
        unset($data['password'], $data['secret'], $data['cliente_secret']);

        return $data;
    }
}
