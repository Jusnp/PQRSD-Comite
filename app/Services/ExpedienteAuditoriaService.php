<?php

namespace App\Services;

use App\Models\Pqrsd;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;
use ZipArchive;

class ExpedienteAuditoriaService
{
    public function __construct(private readonly PqrsdService $pqrsdService) {}

    public function pdf(Pqrsd $caso): string
    {
        $caso->loadMissing([
            'tipo', 'motivo', 'categoria', 'subcategoria', 'sede', 'aseguradora', 'canal',
            'actuaciones.usuario', 'historialEstados.usuario', 'adjuntos', 'encuesta', 'reglaSla',
        ]);

        return Pdf::loadView('reportes.expediente-auditoria', [
            'caso' => $caso,
            'generado' => now('America/Bogota'),
            'firmaDigital' => false,
        ])->setPaper('a4')->output();
    }

    public function zip(Pqrsd $caso, User $usuario): string
    {
        $pdf = $this->pdf($caso);
        $directorio = storage_path('app/temp-auditoria');
        if (! is_dir($directorio)) {
            mkdir($directorio, 0755, true);
        }

        $ruta = $directorio.DIRECTORY_SEPARATOR.'expediente-'.$caso->radicado.'-'.now('America/Bogota')->format('YmdHis').'.zip';
        $zip = new ZipArchive;
        if ($zip->open($ruta, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('No fue posible preparar el paquete de auditoría.');
        }

        $nombrePdf = 'informe-'.$caso->radicado.'.pdf';
        $zip->addFromString($nombrePdf, $pdf);
        $manifiesto = [[
            'nombre' => $nombrePdf,
            'tipo' => 'informe_pdf',
            'sha256' => hash('sha256', $pdf),
            'bytes' => strlen($pdf),
            'firma_digital' => false,
        ]];

        $disco = Storage::disk(config('pqrsd_correo.adjuntos_disk', 'local'));
        foreach ($caso->adjuntos as $adjunto) {
            if ((int) $adjunto->pqrsd_id !== (int) $caso->id || ! $this->rutaAdjuntaPermitida($adjunto->ruta) || ! $disco->exists($adjunto->ruta)) {
                $manifiesto[] = [
                    'nombre' => $this->nombreZipSeguro((string) $adjunto->nombre_original, (int) $adjunto->id),
                    'tipo' => $adjunto->tipo,
                    'id' => $adjunto->id,
                    'sha256' => null,
                    'bytes' => $adjunto->tamano,
                    'ausente' => true,
                ];

                continue;
            }
            $binario = $disco->get($adjunto->ruta);
            $nombre = $this->nombreZipSeguro((string) $adjunto->nombre_original, (int) $adjunto->id);
            $zip->addFromString($nombre, $binario);
            $manifiesto[] = [
                'nombre' => $nombre,
                'tipo' => $adjunto->tipo,
                'id' => $adjunto->id,
                'sha256' => hash('sha256', $binario),
                'bytes' => strlen($binario),
            ];
        }

        $zip->addFromString('manifiesto.json', json_encode([
            'radicado' => $caso->radicado,
            'generado_en' => now('America/Bogota')->toIso8601String(),
            'firma_digital_certificada' => false,
            'archivos' => $manifiesto,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $zip->close();

        $this->pqrsdService->auditar($caso, $usuario, 'exportacion_expediente_zip', null, [
            'radicado' => $caso->radicado,
            'archivos' => count($manifiesto),
        ]);

        return $ruta;
    }

    private function rutaAdjuntaPermitida(?string $ruta): bool
    {
        if ($ruta === null || $ruta === '') {
            return false;
        }

        $normal = str_replace('\\', '/', $ruta);

        return ! str_contains($normal, '..') && ! str_starts_with($normal, '/');
    }

    private function nombreZipSeguro(string $original, int $id): string
    {
        $base = basename(str_replace('\\', '/', $original));
        $seguro = preg_replace('/[^A-Za-z0-9._-]+/', '_', $base) ?: 'soporte';
        $seguro = ltrim((string) $seguro, '.');
        if ($seguro === '' || str_contains($seguro, '..')) {
            $seguro = 'soporte';
        }

        return 'soportes/'.$id.'-'.$seguro;
    }
}
