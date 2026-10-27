<?php

namespace App\Support;

use InvalidArgumentException;

class PublicUrl
{
    /**
     * URL base autorizada para el formulario público / QR.
     *
     * Prioridad: PQRSD_PUBLIC_URL → PQRSD_DEV_URL (no production) → APP_URL.
     * No usa el encabezado Host de la petición.
     */
    public static function base(): string
    {
        $public = self::normalizeBase(config('pqrsd_publico.base_url'));
        if ($public !== null) {
            self::assertUsableBase($public, requireHttpsInProduction: true);

            return $public;
        }

        if (! app()->environment('production')) {
            $dev = self::normalizeBase(config('pqrsd_publico.dev_url'));
            if ($dev !== null) {
                self::assertDevHostAllowed($dev);

                return $dev;
            }
        }

        $appUrl = self::normalizeBase(config('app.url')) ?? 'http://127.0.0.1:8010';

        if (! app()->environment('production')) {
            // Evita QR rotos con dominios .test no resolubles cuando no hay DEV_URL.
            if (self::looksLikeUnresolvedLocalDomain($appUrl)) {
                $fallback = self::normalizeBase(config('pqrsd_publico.dev_url'))
                    ?? 'http://127.0.0.1:8010';
                self::assertDevHostAllowed($fallback);

                return $fallback;
            }

            self::assertDevHostAllowed($appUrl);
        }

        return $appUrl;
    }

    /**
     * Origen de la URL base (para mensajes en UI / depuración).
     */
    public static function source(): string
    {
        if (filled(config('pqrsd_publico.base_url'))) {
            return 'PQRSD_PUBLIC_URL';
        }

        if (! app()->environment('production') && filled(config('pqrsd_publico.dev_url'))) {
            return 'PQRSD_DEV_URL';
        }

        $appUrl = self::normalizeBase(config('app.url')) ?? '';
        if (! app()->environment('production') && self::looksLikeUnresolvedLocalDomain($appUrl)) {
            return 'fallback_local';
        }

        return 'APP_URL';
    }

    /**
     * URL absoluta del formulario público (opcionalmente vía QR).
     */
    public static function formulario(bool $viaQr = false): string
    {
        $path = route('publico.formulario', $viaQr ? ['qr' => 1] : [], false);

        return self::base().$path;
    }

    public static function isDistributable(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);

        if (! is_string($host) || $host === '') {
            return false;
        }

        $host = strtolower($host);

        if (in_array($host, ['localhost', '127.0.0.1', '::1'], true)) {
            return false;
        }

        if (str_ends_with($host, '.test') || str_ends_with($host, '.local') || str_ends_with($host, '.localhost')) {
            return false;
        }

        if (str_contains($host, 'laragon')) {
            return false;
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        if ($scheme === 'http' && app()->environment('production')) {
            return false;
        }

        return $scheme === 'https' || ! app()->environment('production');
    }

    private static function normalizeBase(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = rtrim(trim($value), '/');

        return $value !== '' ? $value : null;
    }

    private static function looksLikeUnresolvedLocalDomain(string $url): bool
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        return $host !== '' && (
            str_ends_with($host, '.test')
            || str_ends_with($host, '.local')
            || str_contains($host, 'laragon')
        );
    }

    private static function assertDevHostAllowed(string $base): void
    {
        $host = strtolower((string) parse_url($base, PHP_URL_HOST));
        $allowed = config('pqrsd_publico.allowed_dev_hosts', ['127.0.0.1', 'localhost', '::1']);

        if ($host === '' || ! in_array($host, $allowed, true)) {
            // También permitir hosts .test/.local solo como no distribuibles si están en APP_URL legacy,
            // pero para DEV_URL exigimos allowlist estricta.
            if (! self::looksLikeUnresolvedLocalDomain($base) || filled(config('pqrsd_publico.dev_url'))) {
                throw new InvalidArgumentException(
                    "Host no permitido para URL de desarrollo del QR: {$host}. ".
                    'Configure PQRSD_DEV_URL / PQRSD_ALLOWED_DEV_HOSTS con un host verificado.'
                );
            }
        }

        $scheme = strtolower((string) parse_url($base, PHP_URL_SCHEME));
        if (! in_array($scheme, ['http', 'https'], true)) {
            throw new InvalidArgumentException('La URL base del QR debe usar http o https.');
        }
    }

    private static function assertUsableBase(string $base, bool $requireHttpsInProduction): void
    {
        $parts = parse_url($base);
        if (! is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            throw new InvalidArgumentException('PQRSD_PUBLIC_URL no es una URL absoluta válida.');
        }

        if ($requireHttpsInProduction && app()->environment('production') && strtolower($parts['scheme']) !== 'https') {
            throw new InvalidArgumentException('En producción PQRSD_PUBLIC_URL debe usar HTTPS.');
        }
    }
}
