@include('errors.marco', [
    'titulo' => 'Página no encontrada',
    'mensaje' => 'La página solicitada no existe en PQRSD.',
    'accion' => auth()->user()?->can('pqrsd.view') ? 'bandeja' : (auth()->check() ? 'publico' : 'login'),
])
