<?php

namespace App\Services\Correo;

use App\Models\Aseguradora;
use App\Models\CuentaCorreo;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class CuentaCorreoInventarioService
{
    /**
     * @return list<string>
     */
    public function csvHeaders(): array
    {
        return [
            'direccion',
            'tipo_buzon',
            'buzon_canonico',
            'responsable_nombre',
            'area_responsable',
            'aseguradora_codigo',
            'estado_autorizacion',
            'observaciones_inventario',
            'activo',
        ];
    }

    public function exportCsv(): string
    {
        $lines = [implode(',', $this->csvHeaders())];

        CuentaCorreo::query()->orderBy('direccion')->each(function (CuentaCorreo $c) use (&$lines) {
            $lines[] = $this->csvLine([
                $c->direccion,
                $c->tipo_buzon ?: 'desconocido',
                $c->buzon_canonico,
                $c->responsable_nombre,
                $c->area_responsable,
                $c->aseguradora?->codigo,
                $c->estado_autorizacion ?: 'pendiente',
                $c->observaciones_inventario,
                $c->activo ? '1' : '0',
            ]);
        });

        return implode("\n", $lines)."\n";
    }

    /**
     * Importa clasificación sin inventar datos: solo actualiza filas existentes por dirección.
     *
     * @return array{actualizadas:int,omitidas:int,errores:list<string>}
     */
    public function importCsv(UploadedFile|string $file): array
    {
        $path = $file instanceof UploadedFile ? $file->getRealPath() : $file;
        $raw = file_get_contents($path);
        if ($raw === false || trim($raw) === '') {
            throw new RuntimeException('CSV vacío');
        }

        $rows = preg_split("/\r\n|\n|\r/", trim($raw)) ?: [];
        $header = str_getcsv(array_shift($rows));
        $expected = $this->csvHeaders();
        if ($header !== $expected) {
            throw new RuntimeException(
                'Encabezados CSV inválidos. Use la plantilla exportada. Esperado: '.implode(',', $expected)
            );
        }

        $actualizadas = 0;
        $omitidas = 0;
        $errores = [];

        DB::transaction(function () use ($rows, &$actualizadas, &$omitidas, &$errores) {
            foreach ($rows as $i => $line) {
                if (trim($line) === '') {
                    continue;
                }
                $cols = str_getcsv($line);
                if (count($cols) < count($this->csvHeaders())) {
                    $errores[] = 'Fila '.($i + 2).': columnas incompletas';
                    continue;
                }

                $data = array_combine($this->csvHeaders(), array_slice($cols, 0, count($this->csvHeaders())));
                $direccion = strtolower(trim((string) $data['direccion']));
                $cuenta = CuentaCorreo::whereRaw('LOWER(direccion) = ?', [$direccion])->first();
                if (! $cuenta) {
                    $omitidas++;
                    $errores[] = 'Fila '.($i + 2).': dirección no existe en catálogo (no se crean buzones desde CSV)';
                    continue;
                }

                $tipo = strtolower(trim((string) $data['tipo_buzon']));
                if (! in_array($tipo, ['individual', 'alias', 'grupo', 'reenvio', 'desconocido', 'otro'], true)) {
                    $errores[] = 'Fila '.($i + 2).': tipo_buzon inválido';
                    continue;
                }

                $auth = strtolower(trim((string) $data['estado_autorizacion']));
                if (! in_array($auth, ['pendiente', 'autorizado', 'rechazado', 'no_aplica'], true)) {
                    $errores[] = 'Fila '.($i + 2).': estado_autorizacion inválido';
                    continue;
                }

                $asegId = $cuenta->aseguradora_id;
                if (filled($data['aseguradora_codigo'])) {
                    $found = Aseguradora::where('codigo', trim((string) $data['aseguradora_codigo']))->value('id');
                    if (! $found) {
                        $errores[] = 'Fila '.($i + 2).': aseguradora_codigo desconocido';
                        continue;
                    }
                    $asegId = $found;
                }

                $cuenta->update([
                    'tipo_buzon' => $tipo,
                    'buzon_canonico' => filled($data['buzon_canonico']) ? trim((string) $data['buzon_canonico']) : null,
                    'responsable_nombre' => filled($data['responsable_nombre']) ? trim((string) $data['responsable_nombre']) : null,
                    'area_responsable' => filled($data['area_responsable']) ? trim((string) $data['area_responsable']) : $cuenta->area_responsable,
                    'aseguradora_id' => $asegId,
                    'estado_autorizacion' => $auth,
                    'observaciones_inventario' => filled($data['observaciones_inventario']) ? trim((string) $data['observaciones_inventario']) : null,
                    'activo' => in_array(trim((string) $data['activo']), ['1', 'true', 'si', 'sí', 'yes'], true),
                    'fecha_ultima_validacion_inventario' => now('America/Bogota'),
                ]);
                $actualizadas++;
            }
        });

        return compact('actualizadas', 'omitidas', 'errores');
    }

    /**
     * Plantilla con las direcciones actuales y celdas de clasificación vacías (desconocido).
     */
    public function plantillaParaAdmin(): string
    {
        return $this->exportCsv();
    }

    /**
     * @param  list<string|null>  $cols
     */
    private function csvLine(array $cols): string
    {
        return collect($cols)->map(function ($v) {
            $v = (string) ($v ?? '');
            if (str_contains($v, ',') || str_contains($v, '"') || str_contains($v, "\n")) {
                return '"'.str_replace('"', '""', $v).'"';
            }

            return $v;
        })->implode(',');
    }

    /**
     * @return Collection<int, array{direccion:string,tipo_buzon:string,pendiente:bool}>
     */
    public function resumenPendientes(): Collection
    {
        return CuentaCorreo::query()
            ->orderBy('direccion')
            ->get()
            ->map(fn (CuentaCorreo $c) => [
                'direccion' => $c->direccion,
                'tipo_buzon' => $c->tipo_buzon ?: 'desconocido',
                'pendiente' => ($c->tipo_buzon ?: 'desconocido') === 'desconocido'
                    || ($c->estado_autorizacion ?: 'pendiente') === 'pendiente',
            ]);
    }
}
