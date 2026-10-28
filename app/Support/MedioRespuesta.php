<?php

namespace App\Support;

class MedioRespuesta
{
    /**
     * @return array<string, string>
     */
    public static function paraNuevasRadicaciones(): array
    {
        return config('pqrsd_publico.medios_respuesta', []);
    }

    /**
     * Incluye correspondencia física para consultar expedientes anteriores.
     *
     * @return array<string, string>
     */
    public static function paraConsulta(): array
    {
        return array_merge(
            self::paraNuevasRadicaciones(),
            config('pqrsd_publico.medios_respuesta_historicos', [])
        );
    }

    public static function etiqueta(?string $codigo): string
    {
        if ($codigo === null || $codigo === '') {
            return '—';
        }

        return self::paraConsulta()[$codigo] ?? $codigo;
    }
}
