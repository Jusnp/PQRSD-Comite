@include('errors.marco', [
    'titulo' => 'Error interno',
    'mensaje' => 'Ocurrió un error interno. Intente de nuevo más tarde.',
    'accion' => auth()->user()?->can('pqrsd.view') ? 'bandeja' : (auth()->check() ? 'publico' : 'login'),
])
