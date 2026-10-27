<?php

namespace App\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;

class PqrsdReporteExport implements FromCollection, WithHeadings
{
    use Exportable;

    public function __construct(private readonly Collection $rows) {}

    public function collection(): Collection
    {
        return $this->rows->map(fn (array $row) => array_values($row));
    }

    public function headings(): array
    {
        return array_keys($this->rows->first() ?? [
            'Radicado' => null,
            'Recepción' => null,
            'Radicación' => null,
            'Tipo' => null,
            'Riesgo' => null,
            'EPS' => null,
            'Sede' => null,
            'Canal' => null,
            'Responsable' => null,
            'Estado operativo' => null,
            'Cumplimiento' => null,
            'Plazo regla' => null,
            'Fecha límite' => null,
            'Fecha respuesta' => null,
            'Horas respuesta' => null,
        ]);
    }
}
