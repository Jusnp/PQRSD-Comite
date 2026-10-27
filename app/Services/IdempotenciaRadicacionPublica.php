<?php

namespace App\Services;

use App\Models\Pqrsd;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Unicidad de la radicación pública.
 *
 * La caché (en este proyecto, almacén database) solo evita el doble envío
 * mientras el formulario sigue abierto. La garantía entre procesos es la
 * clave única en pqrsd_idempotencia_publica: si dos trabajadores insertan
 * la misma clave, la base deja un solo expediente.
 */
class IdempotenciaRadicacionPublica
{
    public function huella(array $campos): string
    {
        $normalizados = $this->normalizar($campos);
        ksort($normalizados);

        return hash('sha256', json_encode($normalizados, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    /**
     * @param  callable(): Pqrsd  $crear
     * @return array{caso: Pqrsd, token: string, repetida: bool}
     */
    public function resolver(string $clave, string $huella, string $tokenNuevo, callable $crear): array
    {
        try {
            return DB::transaction(function () use ($clave, $huella, $tokenNuevo, $crear) {
                $existente = DB::table('pqrsd_idempotencia_publica')
                    ->where('clave', $clave)
                    ->lockForUpdate()
                    ->first();

                if ($existente) {
                    return $this->repetir($existente, $huella);
                }

                DB::table('pqrsd_idempotencia_publica')->insert([
                    'clave' => $clave,
                    'huella' => $huella,
                    'pqrsd_id' => null,
                    'token_cifrado' => Crypt::encryptString($tokenNuevo),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $caso = $crear();

                DB::table('pqrsd_idempotencia_publica')
                    ->where('clave', $clave)
                    ->update([
                        'pqrsd_id' => $caso->id,
                        'updated_at' => now(),
                    ]);

                return [
                    'caso' => $caso,
                    'token' => $tokenNuevo,
                    'repetida' => false,
                ];
            });
        } catch (UniqueConstraintViolationException) {
            $existente = DB::table('pqrsd_idempotencia_publica')->where('clave', $clave)->first();
            if (! $existente) {
                throw new RuntimeException('No se pudo confirmar la radicación anterior. Recargue el formulario e intente de nuevo.');
            }

            return $this->repetir($existente, $huella);
        }
    }

    /**
     * @param  object  $fila
     * @return array{caso: Pqrsd, token: string, repetida: bool}
     */
    private function repetir(object $fila, string $huella): array
    {
        if (! hash_equals((string) $fila->huella, $huella)) {
            throw new ConflictoIdempotenciaPublica;
        }

        if (! $fila->pqrsd_id) {
            throw new RuntimeException('La radicación anterior no terminó. Recargue el formulario e intente de nuevo.');
        }

        return [
            'caso' => Pqrsd::query()->findOrFail($fila->pqrsd_id),
            'token' => Crypt::decryptString((string) $fila->token_cifrado),
            'repetida' => true,
        ];
    }

    /**
     * @param  array<string, mixed>  $campos
     * @return array<string, mixed>
     */
    private function normalizar(array $campos): array
    {
        $salida = [];
        foreach ($campos as $clave => $valor) {
            if (is_array($valor)) {
                $lista = array_map(fn ($item) => is_scalar($item) ? (string) $item : json_encode($item), $valor);
                sort($lista);
                $salida[$clave] = $lista;
                continue;
            }
            $salida[$clave] = is_bool($valor) ? $valor : ($valor === null ? null : (string) $valor);
        }

        return $salida;
    }
}
