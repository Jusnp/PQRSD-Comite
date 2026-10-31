<?php

namespace App\Services;

use App\Models\Pqrsd;
use App\Models\RadicadoAlias;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

/**
 * Resuelve un número que puede ser radicado oficial, alias histórico, o ambos.
 * La consulta ciudadana no elige un expediente si falta una prueba de identidad.
 */
class ResolucionRadicado
{
    public function normalizar(string $radicado): string
    {
        return strtoupper(trim($radicado));
    }

    /**
     * @return Collection<int, array{caso: Pqrsd, vinculo: string}>
     */
    public function coincidencias(string $radicado): Collection
    {
        $radicado = $this->normalizar($radicado);
        $filas = collect();

        foreach (Pqrsd::query()->where('radicado', $radicado)->orderBy('id')->get() as $caso) {
            $filas->push(['caso' => $caso, 'vinculo' => 'oficial']);
        }

        if (! $this->hayAlias()) {
            return $filas->values();
        }

        $idsAlias = RadicadoAlias::query()
            ->where('radicado', $radicado)
            ->orderBy('id')
            ->pluck('pqrsd_id');

        $porAlias = Pqrsd::query()->whereIn('id', $idsAlias)->get()->keyBy('id');
        foreach ($idsAlias as $id) {
            $caso = $porAlias->get($id);
            if ($caso) {
                $filas->push(['caso' => $caso, 'vinculo' => 'alias']);
            }
        }

        return $filas->values();
    }

    public function cantidad(string $radicado): int
    {
        return $this->coincidencias($radicado)
            ->pluck('caso.id')
            ->unique()
            ->count();
    }

    /**
     * Estado público solo cuando el número es el radicado oficial de un único expediente.
     * Un alias, o un número compartido, no autoriza a mostrar el estado de nadie.
     */
    public function consultaPublica(string $radicado): ?Pqrsd
    {
        $coincidencias = $this->coincidencias($radicado);
        $ids = $coincidencias->pluck('caso.id')->unique();
        if ($ids->count() !== 1) {
            return null;
        }

        $oficiales = $coincidencias->where('vinculo', 'oficial');
        if ($oficiales->count() !== 1) {
            return null;
        }

        if ($coincidencias->where('vinculo', 'alias')->contains(
            fn (array $fila): bool => (int) $fila['caso']->id !== (int) $oficiales->first()['caso']->id
        )) {
            return null;
        }

        return $oficiales->first()['caso'];
    }

    public function esAmbiguoParaCiudadano(string $radicado): bool
    {
        $coincidencias = $this->coincidencias($radicado);

        return $coincidencias->isNotEmpty() && $this->consultaPublica($radicado) === null;
    }

    /**
     * El código solo abre el expediente cuyo hash coincide, y solo si el número
     * es el radicado oficial de ese mismo expediente o un alias suyo.
     */
    public function consultaSegura(string $radicado, string $token): ?Pqrsd
    {
        $radicado = $this->normalizar($radicado);
        $hash = hash('sha256', $token);
        $casos = Pqrsd::query()->where('consulta_token_hash', $hash)->get();
        if ($casos->count() !== 1) {
            return null;
        }

        $caso = $casos->first();
        if (hash_equals((string) $caso->radicado, $radicado)) {
            return $caso;
        }

        if (! $this->hayAlias()) {
            return null;
        }

        $pertenece = RadicadoAlias::query()
            ->where('pqrsd_id', $caso->id)
            ->where('radicado', $radicado)
            ->exists();

        return $pertenece ? $caso : null;
    }

    private function hayAlias(): bool
    {
        return Schema::hasTable('radicados_alias');
    }
}
