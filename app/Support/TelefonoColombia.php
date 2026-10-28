<?php

namespace App\Support;

/**
 * Teléfonos móviles y fijos de Colombia.
 * No exige teléfono en radicación anónima: ese caso se resuelve en el formulario.
 */
class TelefonoColombia
{
    public static function digitos(?string $valor): string
    {
        $digitos = preg_replace('/\D+/', '', (string) $valor) ?? '';
        if (str_starts_with($digitos, '57') && strlen($digitos) > 10) {
            $digitos = substr($digitos, 2);
        }

        return $digitos;
    }

    public static function esValido(?string $valor): bool
    {
        $digitos = self::digitos($valor);
        if ($digitos === '' || preg_match('/^(\d)\1+$/', $digitos) === 1) {
            return false;
        }

        return preg_match('/^3\d{9}$/', $digitos) === 1
            || preg_match('/^60[1-8]\d{7}$/', $digitos) === 1
            || preg_match('/^[1-8]\d{6}$/', $digitos) === 1;
    }
}
