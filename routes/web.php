<?php

use App\Http\Controllers\Catalogo\AreaInstitucionalController;
use App\Http\Controllers\Catalogo\AseguradoraController;
use App\Http\Controllers\Catalogo\CanalIngresoController;
use App\Http\Controllers\Catalogo\MotivoPqrsdController;
use App\Http\Controllers\Catalogo\ReglaSlaController;
use App\Http\Controllers\Catalogo\SedeController;
use App\Http\Controllers\Catalogo\TipoPqrsdController;
use App\Http\Controllers\Correo\BandejaCorreoController;
use App\Http\Controllers\Correo\CuentaCorreoController;
use App\Http\Controllers\AdjuntoPqrsdController;
use App\Http\Controllers\Gmail\GmailOAuthController;
use App\Http\Controllers\Gmail\GmailPubSubController;
use App\Http\Controllers\OpsHealthController;
use App\Http\Controllers\PqrsdController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\RemisionInternaController;
use App\Http\Controllers\Publico\FormularioPublicoController;
use App\Http\Controllers\IndicadoresPqrsdController;
use App\Http\Controllers\ReporteController;
use App\Http\Controllers\ThemePreferenceController;
use App\Http\Controllers\UsuarioController;
use App\Http\Controllers\VencimientoController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return auth()->check()
        ? redirect()->route('pqrsd.index')
        : redirect()->route('login');
});

Route::post('/webhooks/gmail-pubsub', GmailPubSubController::class)
    ->middleware('throttle:120,1')
    ->name('webhooks.gmail-pubsub');

Route::prefix('publico')->name('publico.')->group(function () {
    Route::get('/pqrsd', [FormularioPublicoController::class, 'create'])->name('formulario');
    Route::post('/pqrsd', [FormularioPublicoController::class, 'store'])
        ->middleware('throttle:10,10')
        ->name('store');
    Route::get('/confirmacion', [FormularioPublicoController::class, 'confirmacion'])->name('confirmacion');
    Route::get('/consulta', [FormularioPublicoController::class, 'consulta'])->name('consulta');
    Route::post('/consulta', [FormularioPublicoController::class, 'consulta'])
        ->middleware('throttle:20,10')
        ->name('consulta.store');
    Route::get('/consulta-segura', [FormularioPublicoController::class, 'consultaSegura'])->name('consulta.segura');
    Route::post('/consulta-segura', [FormularioPublicoController::class, 'consultaSegura'])
        ->middleware('throttle:20,10')
        ->name('consulta.segura.store');
    Route::get('/qr', [FormularioPublicoController::class, 'qr'])->name('qr');
    Route::get('/qr/descargar', [FormularioPublicoController::class, 'qrDownload'])->name('qr.download');
});

Route::post('/preferencias/tema', [ThemePreferenceController::class, 'update'])
    ->name('preferencias.tema');

Route::middleware(['auth', 'cuenta.activa'])->group(function () {
    // Compatibilidad: el Dashboard independiente se eliminó; redirige a la bandeja.
    Route::get('/dashboard', function () {
        return redirect()->route('pqrsd.index');
    })->name('dashboard');

    Route::middleware('permission:pqrsd.view')->group(function () {
        Route::get('/pqrsd', [PqrsdController::class, 'index'])->name('pqrsd.index');
        Route::get('/pqrsd/{pqrsd}', [PqrsdController::class, 'show'])->name('pqrsd.show');
        Route::get('/pqrsd/{pqrsd}/adjuntos/{adjunto}', [AdjuntoPqrsdController::class, 'download'])
            ->name('pqrsd.adjuntos.download');
        Route::get('/vencimientos/proximos', [VencimientoController::class, 'proximos'])->name('vencimientos.proximos');
        Route::get('/vencimientos/vencidos', [VencimientoController::class, 'vencidos'])->name('vencimientos.vencidos');
        Route::get('/vencimientos/vitales', [VencimientoController::class, 'vitales'])->name('vencimientos.vitales');
    });

    Route::middleware('permission:pqrsd.create_manual')->group(function () {
        Route::get('/pqrsd-create', [PqrsdController::class, 'create'])->name('pqrsd.create');
        Route::post('/pqrsd', [PqrsdController::class, 'store'])->name('pqrsd.store');
    });

    Route::post('/pqrsd/{pqrsd}/clasificar', [PqrsdController::class, 'clasificar'])
        ->middleware('permission:pqrsd.classify|pqrsd.manage')
        ->name('pqrsd.clasificar');

    Route::post('/pqrsd/{pqrsd}/asignar', [PqrsdController::class, 'asignar'])
        ->middleware('permission:pqrsd.assign|pqrsd.manage')
        ->name('pqrsd.asignar');

    Route::post('/pqrsd/{pqrsd}/actuaciones', [PqrsdController::class, 'actuacion'])
        ->middleware('permission:pqrsd.update|pqrsd.manage')
        ->name('pqrsd.actuaciones.store');

    Route::post('/pqrsd/{pqrsd}/adjuntos', [PqrsdController::class, 'storeAdjunto'])
        ->middleware('permission:pqrsd.update|pqrsd.manage')
        ->name('pqrsd.adjuntos.store');

    Route::middleware('permission:pqrsd.update|pqrsd.manage')->group(function () {
        Route::get('/pqrsd/{pqrsd}/remisiones/create', [RemisionInternaController::class, 'create'])
            ->name('pqrsd.remisiones.create');
        Route::post('/pqrsd/{pqrsd}/remisiones', [RemisionInternaController::class, 'store'])
            ->name('pqrsd.remisiones.store');
        Route::post('/pqrsd/{pqrsd}/remisiones/{remision}/remitir', [RemisionInternaController::class, 'remitir'])
            ->whereNumber('remision')
            ->name('pqrsd.remisiones.remitir');
        Route::post('/pqrsd/{pqrsd}/remisiones/{remision}/respuesta', [RemisionInternaController::class, 'respuesta'])
            ->whereNumber('remision')
            ->name('pqrsd.remisiones.respuesta');
        Route::post('/pqrsd/{pqrsd}/remisiones/{remision}/finalizar', [RemisionInternaController::class, 'finalizar'])
            ->whereNumber('remision')
            ->name('pqrsd.remisiones.finalizar');
    });

    Route::middleware('permission:pqrsd.view')->group(function () {
        Route::get('/pqrsd/{pqrsd}/remisiones/{remision}', [RemisionInternaController::class, 'show'])
            ->whereNumber('remision')
            ->name('pqrsd.remisiones.show');
    });

    Route::post('/pqrsd/{pqrsd}/responder', [PqrsdController::class, 'responder'])
        ->middleware('permission:pqrsd.update|pqrsd.manage')
        ->name('pqrsd.responder');

    Route::post('/pqrsd/{pqrsd}/cerrar', [PqrsdController::class, 'cerrar'])
        ->middleware('permission:pqrsd.close|pqrsd.manage')
        ->name('pqrsd.cerrar');

    Route::middleware('permission:usuarios.manage')->group(function () {
        Route::get('usuarios', [UsuarioController::class, 'index'])->name('usuarios.index');
        Route::get('usuarios/create', [UsuarioController::class, 'create'])->name('usuarios.create');
        Route::post('usuarios', [UsuarioController::class, 'store'])->name('usuarios.store');
        Route::get('usuarios/{usuario}', [UsuarioController::class, 'show'])->name('usuarios.show');
        Route::get('usuarios/{usuario}/edit', [UsuarioController::class, 'edit'])->name('usuarios.edit');
        Route::put('usuarios/{usuario}', [UsuarioController::class, 'update'])->name('usuarios.update');
        Route::post('usuarios/{usuario}/emitir', [UsuarioController::class, 'emitir'])->name('usuarios.emitir');
        Route::post('usuarios/{usuario}/vincular', [UsuarioController::class, 'vincular'])->name('usuarios.vincular');
        Route::post('usuarios/{usuario}/reinvitar', [UsuarioController::class, 'reinvitar'])->name('usuarios.reinvitar');
        Route::post('usuarios/{usuario}/revocar', [UsuarioController::class, 'revocar'])->name('usuarios.revocar');
        Route::post('usuarios/{usuario}/recuperar', [UsuarioController::class, 'recuperar'])->name('usuarios.recuperar');
        Route::post('usuarios/{usuario}/desactivar', [UsuarioController::class, 'desactivar'])->name('usuarios.desactivar');
        Route::post('usuarios/{usuario}/reactivar', [UsuarioController::class, 'reactivar'])->name('usuarios.reactivar');
    });

    Route::middleware('permission:sedes.manage')->group(function () {
        Route::resource('sedes', SedeController::class)->except(['show', 'destroy']);
    });

    Route::middleware('permission:aseguradoras.manage')->group(function () {
        Route::resource('aseguradoras', AseguradoraController::class)->except(['show', 'destroy']);
    });

    Route::middleware('permission:canales.manage')->group(function () {
        Route::resource('canales', CanalIngresoController::class)
            ->parameters(['canales' => 'canal'])
            ->except(['show', 'destroy']);
    });

    Route::middleware('permission:tipos.manage')->group(function () {
        Route::resource('tipos', TipoPqrsdController::class)
            ->parameters(['tipos' => 'tipo'])
            ->except(['show', 'destroy']);
    });

    Route::middleware('permission:motivos.manage')->group(function () {
        Route::resource('motivos', MotivoPqrsdController::class)
            ->parameters(['motivos' => 'motivo'])
            ->except(['show', 'destroy']);
    });

    Route::middleware('permission:areas.manage')->group(function () {
        Route::resource('areas', AreaInstitucionalController::class)
            ->parameters(['areas' => 'area'])
            ->except(['show', 'destroy']);
    });

    Route::middleware('permission:reglas_sla.manage')->group(function () {
        Route::resource('reglas', ReglaSlaController::class)
            ->parameters(['reglas' => 'regla'])
            ->except(['show', 'destroy']);
    });

    Route::middleware('permission:correos.manage')->group(function () {
        Route::resource('cuentas-correo', CuentaCorreoController::class)
            ->parameters(['cuentas-correo' => 'cuentaCorreo'])
            ->except(['show', 'destroy']);
        Route::post('cuentas-correo/{cuentaCorreo}/probar', [CuentaCorreoController::class, 'probar'])
            ->name('cuentas-correo.probar');
        Route::post('cuentas-correo/{cuentaCorreo}/sincronizar', [CuentaCorreoController::class, 'sincronizar'])
            ->name('cuentas-correo.sincronizar');
        Route::get('cuentas-correo/{cuentaCorreo}/oauth', [GmailOAuthController::class, 'redirect'])
            ->name('cuentas-correo.oauth');
        Route::post('cuentas-correo/{cuentaCorreo}/oauth/revocar', [CuentaCorreoController::class, 'revocarOauth'])
            ->name('cuentas-correo.oauth.revocar');
        Route::get('oauth/gmail/callback', [GmailOAuthController::class, 'callback'])
            ->name('oauth.gmail.callback');
        Route::get('cuentas-correo-inventario/export', [CuentaCorreoController::class, 'exportInventario'])
            ->name('cuentas-correo.inventario.export');
        Route::post('cuentas-correo-inventario/import', [CuentaCorreoController::class, 'importInventario'])
            ->name('cuentas-correo.inventario.import');
        Route::get('/ops/salud', OpsHealthController::class)->name('ops.health');
    });

    Route::get('/ops/salud-auditor', OpsHealthController::class)
        ->middleware('permission:auditoria.view')
        ->name('ops.health.auditor');

    Route::middleware('permission:correos.review')->group(function () {
        Route::get('bandeja-correo', [BandejaCorreoController::class, 'index'])->name('bandeja-correo.index');
        Route::get('bandeja-correo/{mensaje}', [BandejaCorreoController::class, 'show'])->name('bandeja-correo.show');
        Route::post('bandeja-correo/{mensaje}/confirmar', [BandejaCorreoController::class, 'confirmar'])
            ->name('bandeja-correo.confirmar');
        Route::post('bandeja-correo/{mensaje}/asociar', [BandejaCorreoController::class, 'asociar'])
            ->name('bandeja-correo.asociar');
        Route::post('bandeja-correo/{mensaje}/descartar', [BandejaCorreoController::class, 'descartar'])
            ->name('bandeja-correo.descartar');
    });

    Route::middleware('permission:correos.reply')->group(function () {
        Route::post('bandeja-correo/{mensaje}/responder', [BandejaCorreoController::class, 'responder'])
            ->name('bandeja-correo.responder');
    });

    Route::middleware('permission:reportes.view')->group(function () {
        Route::get('/reportes', [ReporteController::class, 'index'])->name('reportes.index');
        Route::get('/reportes/excel', [ReporteController::class, 'excel'])->name('reportes.excel');
        Route::get('/reportes/pdf', [ReporteController::class, 'pdf'])->name('reportes.pdf');
        Route::get('/reportes/indicadores', [IndicadoresPqrsdController::class, 'index'])->name('reportes.indicadores');
        Route::get('/reportes/indicadores/excel', [IndicadoresPqrsdController::class, 'excel'])->name('reportes.indicadores.excel');
        Route::get('/reportes/indicadores/pdf', [IndicadoresPqrsdController::class, 'pdf'])->name('reportes.indicadores.pdf');
    });

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
