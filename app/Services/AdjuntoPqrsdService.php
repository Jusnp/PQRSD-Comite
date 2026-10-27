<?php

namespace App\Services;

use App\Models\AdjuntoPqrsd;
use App\Models\AuditoriaPqrsd;
use App\Models\Pqrsd;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AdjuntoPqrsdService
{
    /**
     * Almacena adjuntos en disco privado y los asocia al expediente.
     * Si falla el almacenamiento de alguno, revierte la transacción de BD
     * y elimina archivos ya escritos en este lote.
     *
     * @param  list<UploadedFile>  $files
     * @return list<AdjuntoPqrsd>
     */
    public function guardarSoportesPublicos(Pqrsd $caso, array $files, ?User $usuario = null): array
    {
        return $this->guardarSoportes($caso, $files, $usuario, 'soporte_publico', 'formulario_publico');
    }

    /**
     * Adjuntos de soporte cargados por operadores autorizados (disco privado).
     *
     * @param  list<UploadedFile>  $files
     * @return list<AdjuntoPqrsd>
     */
    public function guardarSoportesInternos(
        Pqrsd $caso,
        array $files,
        User $usuario,
        ?int $remisionInternaId = null,
        string $tipoAdjunto = 'soporte_interno'
    ): array {
        return $this->guardarSoportes(
            $caso,
            $files,
            $usuario,
            $tipoAdjunto,
            $remisionInternaId ? 'remision_interna' : 'gestion_interna',
            $remisionInternaId
        );
    }

    /**
     * @param  list<UploadedFile>  $files
     * @return list<AdjuntoPqrsd>
     */
    private function guardarSoportes(
        Pqrsd $caso,
        array $files,
        ?User $usuario,
        string $tipoAdjunto,
        string $origen,
        ?int $remisionInternaId = null
    ): array {
        $cfg = config('pqrsd_publico.adjuntos');
        $maxFiles = (int) ($cfg['max_files'] ?? 5);
        $maxKb = (int) ($cfg['max_kb'] ?? 10240);
        $allowedExt = array_map('strtolower', $cfg['extensions'] ?? []);
        $allowedMimes = $cfg['mimes'] ?? [];

        if (count($files) > $maxFiles) {
            throw ValidationException::withMessages([
                'adjuntos' => "Máximo {$maxFiles} archivos por solicitud.",
            ]);
        }

        $diskName = config('pqrsd_correo.adjuntos_disk', 'local');
        $disk = Storage::disk($diskName);
        $guardados = [];
        $rutasEscritas = [];

        try {
            return DB::transaction(function () use (
                $caso, $files, $usuario, $maxKb, $allowedExt, $allowedMimes, $disk,
                $tipoAdjunto, $origen, $remisionInternaId, &$guardados, &$rutasEscritas
            ) {
                foreach ($files as $index => $file) {
                    if (! $file instanceof UploadedFile || ! $file->isValid()) {
                        throw ValidationException::withMessages([
                            'adjuntos' => 'Uno de los archivos no se pudo cargar correctamente.',
                        ]);
                    }

                    $ext = strtolower($file->getClientOriginalExtension() ?: $file->extension() ?: '');
                    if ($ext === '' || ! in_array($ext, $allowedExt, true)) {
                        throw ValidationException::withMessages([
                            'adjuntos' => "Extensión no permitida: .{$ext}",
                        ]);
                    }

                    if ($file->getSize() > $maxKb * 1024) {
                        throw ValidationException::withMessages([
                            'adjuntos' => 'Cada archivo puede pesar máximo '.round($maxKb / 1024, 1).' MB.',
                        ]);
                    }

                    $mime = $this->normalizeMime($this->detectMime($file), $ext);
                    if (! in_array($mime, $allowedMimes, true)) {
                        throw ValidationException::withMessages([
                            'adjuntos' => "Tipo de archivo no permitido ({$mime}).",
                        ]);
                    }

                    if (! $this->extensionCompatibleConMime($ext, $mime, $file)) {
                        throw ValidationException::withMessages([
                            'adjuntos' => 'La extensión del archivo no coincide con su contenido.',
                        ]);
                    }

                    $nombreInterno = Str::uuid()->toString().'.'.$ext;
                    $ruta = 'pqrsd/'.$caso->id.'/'.$nombreInterno;

                    $stream = fopen($file->getRealPath(), 'r');
                    if ($stream === false) {
                        throw ValidationException::withMessages([
                            'adjuntos' => 'No fue posible leer el archivo subido.',
                        ]);
                    }

                    $ok = $disk->put($ruta, $stream);
                    if (is_resource($stream)) {
                        fclose($stream);
                    }

                    if (! $ok) {
                        throw ValidationException::withMessages([
                            'adjuntos' => 'Error al almacenar el archivo. Intente de nuevo.',
                        ]);
                    }

                    $rutasEscritas[] = $ruta;

                    $adjunto = AdjuntoPqrsd::create([
                        'pqrsd_id' => $caso->id,
                        'remision_interna_id' => $remisionInternaId,
                        'subido_por' => $usuario?->id,
                        'nombre_original' => Str::limit($file->getClientOriginalName(), 200, ''),
                        'ruta' => $ruta,
                        'mime' => $mime,
                        'tamano' => (int) $file->getSize(),
                        'tipo' => $tipoAdjunto,
                    ]);

                    AuditoriaPqrsd::create([
                        'pqrsd_id' => $caso->id,
                        'usuario_id' => $usuario?->id,
                        'accion' => 'adjunto.cargar',
                        'entidad' => 'adjunto_pqrsd',
                        'entidad_id' => $adjunto->id,
                        'antes' => null,
                        'despues' => [
                            'nombre_original' => $adjunto->nombre_original,
                            'mime' => $adjunto->mime,
                            'tamano' => $adjunto->tamano,
                            'origen' => $origen,
                            'tipo' => $tipoAdjunto,
                            'remision_interna_id' => $remisionInternaId,
                            'indice' => $index,
                        ],
                        'ip' => request()->ip(),
                        'user_agent' => substr((string) request()->userAgent(), 0, 255),
                    ]);

                    $guardados[] = $adjunto;
                }

                return $guardados;
            });
        } catch (\Throwable $e) {
            foreach ($rutasEscritas as $ruta) {
                $disk->delete($ruta);
            }
            throw $e;
        }
    }

    private function detectMime(UploadedFile $file): string
    {
        $path = $file->getRealPath();
        if ($path && is_file($path) && class_exists(\finfo::class)) {
            $finfo = new \finfo(FILEINFO_MIME_TYPE);
            $detected = $finfo->file($path);
            if (is_string($detected) && $detected !== '') {
                return $detected;
            }
        }

        return (string) ($file->getMimeType() ?: 'application/octet-stream');
    }

    private function normalizeMime(string $mime, string $ext): string
    {
        // finfo suele reportar DOCX como application/zip
        if ($ext === 'docx' && in_array($mime, ['application/zip', 'application/x-zip-compressed', 'application/octet-stream'], true)) {
            return 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
        }

        if ($ext === 'doc' && $mime === 'application/octet-stream') {
            return 'application/msword';
        }

        return $mime;
    }

    private function extensionCompatibleConMime(string $ext, string $mime, UploadedFile $file): bool
    {
        $map = [
            'pdf' => ['application/pdf'],
            'doc' => ['application/msword'],
            'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
            'jpg' => ['image/jpeg'],
            'jpeg' => ['image/jpeg'],
            'png' => ['image/png'],
            'webp' => ['image/webp'],
        ];

        $allowed = $map[$ext] ?? [];
        if (! in_array($mime, $allowed, true)) {
            return false;
        }

        if ($ext === 'docx') {
            $head = @file_get_contents($file->getRealPath(), false, null, 0, 4);

            return is_string($head) && str_starts_with($head, 'PK');
        }

        if ($ext === 'pdf') {
            $head = @file_get_contents($file->getRealPath(), false, null, 0, 5);

            return is_string($head) && str_starts_with($head, '%PDF');
        }

        return true;
    }
}
