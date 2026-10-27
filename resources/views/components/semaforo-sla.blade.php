@props([
    'estado' => 'sin_clasificar',
    'mostrarTexto' => true,
    'size' => 'md',
])

@php
    $mapa = [
        'dentro_plazo' => ['color' => '#16a34a', 'label' => 'Dentro del plazo', 'cls' => 'badge-green'],
        'proximo_vencer' => ['color' => '#ca8a04', 'label' => 'Próximo a vencer', 'cls' => 'badge-yellow'],
        'vencido' => ['color' => '#ef4444', 'label' => 'Vencido', 'cls' => 'badge-red'],
        'resuelto_oportuno' => ['color' => '#16a34a', 'label' => 'Respondido oportunamente', 'cls' => 'badge-green'],
        'resuelto_extemporaneo' => ['color' => '#ef4444', 'label' => 'Respondido tardíamente', 'cls' => 'badge-red'],
        'sin_clasificar' => ['color' => '#7890a5', 'label' => 'Sin clasificar', 'cls' => 'badge-muted'],
    ];
    $info = $mapa[$estado] ?? $mapa['sin_clasificar'];
    $dim = $size === 'lg' ? 18 : 12;
@endphp

<span {{ $attributes->merge(['class' => 'semaforo-sla', 'style' => 'display:inline-flex;align-items:center;gap:8px']) }}>
    <span title="{{ $info['label'] }}" style="width:{{ $dim }}px;height:{{ $dim }}px;border-radius:50%;background:{{ $info['color'] }};box-shadow:0 0 0 3px {{ $info['color'] }}33;display:inline-block;flex-shrink:0"></span>
    @if($mostrarTexto)
        <span class="badge {{ $info['cls'] }}">{{ $info['label'] }}</span>
    @endif
</span>
