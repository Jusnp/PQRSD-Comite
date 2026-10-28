@include('errors.marco', [
    'titulo' => 'Demasiadas solicitudes',
    'mensaje' => 'Espere un momento e intente de nuevo. No se muestran datos internos.',
    'accion' => auth()->user()?->can('pqrsd.view') ? 'bandeja' : (auth()->check() ? 'publico' : 'login'),
])
