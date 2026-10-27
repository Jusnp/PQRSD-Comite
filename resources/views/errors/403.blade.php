@include('errors.marco', [
    'titulo' => 'Acceso restringido',
    'mensaje' => 'No tienes permisos para acceder a esta sección.',
    'accion' => auth()->user()?->can('pqrsd.view') ? 'bandeja' : (auth()->check() ? 'publico' : 'login'),
])
