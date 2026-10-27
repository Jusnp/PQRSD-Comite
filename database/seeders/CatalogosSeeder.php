<?php

namespace Database\Seeders;

use App\Models\AreaInstitucional;
use App\Models\Aseguradora;
use App\Models\CanalIngreso;
use App\Models\Festivo;
use App\Models\MotivoPqrsd;
use App\Models\ReglaSla;
use App\Models\Sede;
use App\Models\TipoPqrsd;
use App\Models\VersionReglaSla;
use Illuminate\Database\Seeder;

class CatalogosSeeder extends Seeder
{
    public function run(): void
    {
        $sedes = [
            ['codigo' => 'URABA', 'nombre' => 'Sede Urabá', 'ciudad' => 'Apartadó', 'activo' => true],
            ['codigo' => 'MEDELLIN', 'nombre' => 'Sede Medellín', 'ciudad' => 'Medellín', 'activo' => true],
            ['codigo' => 'CALL', 'nombre' => 'Call Center', 'ciudad' => 'Medellín', 'activo' => true],
        ];

        foreach ($sedes as $sede) {
            Sede::updateOrCreate(['codigo' => $sede['codigo']], $sede);
        }

        $areas = [
            ['codigo' => 'PSICOLOGIA', 'nombre' => 'Psicología', 'orden' => 10],
            ['codigo' => 'PSIQUIATRIA', 'nombre' => 'Psiquiatría', 'orden' => 20],
            ['codigo' => 'FACTURACION', 'nombre' => 'Facturación', 'orden' => 30],
            ['codigo' => 'ADMISIONES', 'nombre' => 'Admisiones / Call Center', 'orden' => 40],
            ['codigo' => 'COORDINACION_CLINICA', 'nombre' => 'Coordinación clínica', 'orden' => 50],
            ['codigo' => 'CALIDAD', 'nombre' => 'Calidad', 'orden' => 60],
            ['codigo' => 'TALENTO_HUMANO', 'nombre' => 'Talento humano', 'orden' => 70],
            ['codigo' => 'SISTEMAS', 'nombre' => 'Sistemas / TI', 'orden' => 80],
            ['codigo' => 'OTRA', 'nombre' => 'Otra área institucional', 'orden' => 99],
        ];

        foreach ($areas as $area) {
            AreaInstitucional::updateOrCreate(
                ['codigo' => $area['codigo']],
                array_merge($area, ['activo' => true])
            );
        }

        $aseguradoras = [
            ['codigo' => 'SAVIA', 'nombre' => 'Savia Salud', 'tipo' => 'eps', 'activo' => true],
            ['codigo' => 'NUEVAEPS', 'nombre' => 'Nueva EPS', 'tipo' => 'eps', 'activo' => true],
            ['codigo' => 'SURA', 'nombre' => 'EPS Sura', 'tipo' => 'eps', 'activo' => true],
            ['codigo' => 'COOSALUD', 'nombre' => 'Coosalud', 'tipo' => 'eps', 'activo' => true],
            ['codigo' => 'COOMEVA', 'nombre' => 'Coomeva', 'tipo' => 'eps', 'activo' => true],
            ['codigo' => 'SURA_POLIZA', 'nombre' => 'Sura Póliza', 'tipo' => 'poliza', 'activo' => true],
            ['codigo' => 'ARL_SURA', 'nombre' => 'ARL Sura', 'tipo' => 'arl', 'activo' => true],
        ];

        foreach ($aseguradoras as $item) {
            Aseguradora::updateOrCreate(['codigo' => $item['codigo']], $item);
        }

        $canales = [
            ['codigo' => 'MANUAL', 'nombre' => 'Registro manual', 'descripcion' => 'Radicación por funcionario', 'requiere_digitalizacion' => false],
            ['codigo' => 'WEB', 'nombre' => 'Formulario web', 'descripcion' => 'Formulario público', 'requiere_digitalizacion' => false],
            ['codigo' => 'QR', 'nombre' => 'Código QR', 'descripcion' => 'Formulario vía QR en sedes', 'requiere_digitalizacion' => false],
            ['codigo' => 'CORREO', 'nombre' => 'Correo electrónico', 'descripcion' => 'Cuentas institucionales', 'requiere_digitalizacion' => false],
            ['codigo' => 'BUZON', 'nombre' => 'Buzón físico', 'descripcion' => 'Digitalización de buzones', 'requiere_digitalizacion' => true],
            ['codigo' => 'PRESENCIAL', 'nombre' => 'Presencial', 'descripcion' => 'Atención en sede', 'requiere_digitalizacion' => false],
            ['codigo' => 'EPS', 'nombre' => 'Remisión EPS/aseguradora', 'descripcion' => 'Casos remitidos por aseguradoras', 'requiere_digitalizacion' => false],
            ['codigo' => 'AUTORIDAD', 'nombre' => 'Autoridad / Supersalud', 'descripcion' => 'Casos de autoridades', 'requiere_digitalizacion' => false],
        ];

        foreach ($canales as $canal) {
            CanalIngreso::updateOrCreate(
                ['codigo' => $canal['codigo']],
                array_merge($canal, ['activo' => true])
            );
        }

        $tipos = [
            ['codigo' => 'PETICION', 'nombre' => 'Petición', 'orden' => 1],
            ['codigo' => 'QUEJA', 'nombre' => 'Queja', 'orden' => 2],
            ['codigo' => 'RECLAMO', 'nombre' => 'Reclamo', 'orden' => 3],
            ['codigo' => 'SUGERENCIA', 'nombre' => 'Sugerencia', 'orden' => 4],
            ['codigo' => 'DENUNCIA', 'nombre' => 'Denuncia', 'orden' => 5],
            ['codigo' => 'FELICITACION', 'nombre' => 'Felicitación', 'orden' => 6],
        ];

        foreach ($tipos as $tipo) {
            $modelo = TipoPqrsd::updateOrCreate(
                ['codigo' => $tipo['codigo']],
                array_merge($tipo, ['activo' => true])
            );

            MotivoPqrsd::updateOrCreate(
                ['tipo_pqrsd_id' => $modelo->id, 'codigo' => 'GENERAL'],
                [
                    'nombre' => 'Motivo general - '.$tipo['nombre'],
                    'descripcion' => 'Motivo inicial de catálogo',
                    'activo' => true,
                ]
            );
        }

        $reglas = [
            [
                'codigo' => 'VITAL_MENOR_8H',
                'nombre' => 'Riesgo vital en menores',
                'nivel_riesgo' => 'vital_menor',
                'cantidad' => 8,
                'unidad' => 'horas_calendario',
                'prioridad' => 10,
            ],
            [
                'codigo' => 'VITAL_GENERAL_24H',
                'nombre' => 'Riesgo vital general',
                'nivel_riesgo' => 'vital_general',
                'cantidad' => 24,
                'unidad' => 'horas_calendario',
                'prioridad' => 20,
            ],
            [
                'codigo' => 'PRIORIZADO_48H',
                'nombre' => 'Riesgo priorizado',
                'nivel_riesgo' => 'priorizado',
                'cantidad' => 48,
                'unidad' => 'horas_calendario',
                'prioridad' => 30,
            ],
            [
                'codigo' => 'SIMPLE_72H',
                'nombre' => 'Riesgo simple',
                'nivel_riesgo' => 'simple',
                'cantidad' => 72,
                'unidad' => 'horas_calendario',
                'prioridad' => 40,
            ],
            [
                'codigo' => 'ORDINARIO_10DH',
                'nombre' => 'Peticiones y quejas ordinarias',
                'descripcion' => 'Política inicial pendiente de validación jurídica',
                'nivel_riesgo' => 'ordinario',
                'cantidad' => 10,
                'unidad' => 'dias_habiles',
                'prioridad' => 50,
            ],
            // Denuncias: 10 días hábiles (parámetro institucional de trabajo en desarrollo).
            // Validación jurídica / aprobación para producción: pendiente.
            [
                'codigo' => 'DENUNCIA_10DH',
                'nombre' => 'Denuncias — 10 días hábiles',
                'descripcion' => 'Parámetro institucional de trabajo (desarrollo). 10 días hábiles CO desde recepción. Validación jurídica para producción pendiente.',
                'nivel_riesgo' => 'ordinario',
                'cantidad' => 10,
                'unidad' => 'dias_habiles',
                // Prioridad alta (después de ORDINARIO_10DH) para no interceptar reglaParaNivel('ordinario').
                // La selección de denuncias usa codigo DENUNCIA_10DH vía reglaParaCaso().
                'prioridad' => 90,
                'activo' => true,
            ],
        ];

        foreach ($reglas as $regla) {
            $activo = array_key_exists('activo', $regla) ? (bool) $regla['activo'] : true;
            unset($regla['activo']);

            $modelo = ReglaSla::updateOrCreate(
                ['codigo' => $regla['codigo']],
                array_merge($regla, [
                    'activo' => $activo,
                    'version' => 1,
                    'vigente_desde' => now('America/Bogota'),
                ])
            );

            VersionReglaSla::updateOrCreate(
                ['regla_sla_id' => $modelo->id, 'version' => 1],
                [
                    'snapshot' => $modelo->toSnapshot(),
                    'motivo' => $modelo->codigo === 'DENUNCIA_10DH'
                        ? 'Parámetro institucional desarrollo: 10 días hábiles (aprobación jurídica producción pendiente)'
                        : 'Versión inicial',
                ]
            );
        }

        // Retirar regla provisional anterior (no recalcula casos ya radicados).
        ReglaSla::query()
            ->where('codigo', 'DENUNCIA_10D_PENDIENTE')
            ->update(['activo' => false]);

        $festivos2026 = [
            ['fecha' => '2026-01-01', 'nombre' => 'Año Nuevo'],
            ['fecha' => '2026-01-12', 'nombre' => 'Reyes Magos'],
            ['fecha' => '2026-03-23', 'nombre' => 'San José'],
            ['fecha' => '2026-04-02', 'nombre' => 'Jueves Santo'],
            ['fecha' => '2026-04-03', 'nombre' => 'Viernes Santo'],
            ['fecha' => '2026-05-01', 'nombre' => 'Día del Trabajo'],
            ['fecha' => '2026-05-18', 'nombre' => 'Ascensión del Señor'],
            ['fecha' => '2026-06-08', 'nombre' => 'Corpus Christi'],
            ['fecha' => '2026-06-15', 'nombre' => 'Sagrado Corazón'],
            ['fecha' => '2026-06-29', 'nombre' => 'San Pedro y San Pablo'],
            ['fecha' => '2026-07-20', 'nombre' => 'Independencia'],
            ['fecha' => '2026-08-07', 'nombre' => 'Batalla de Boyacá'],
            ['fecha' => '2026-08-17', 'nombre' => 'Asunción de la Virgen'],
            ['fecha' => '2026-10-12', 'nombre' => 'Día de la Raza'],
            ['fecha' => '2026-11-02', 'nombre' => 'Todos los Santos'],
            ['fecha' => '2026-11-16', 'nombre' => 'Independencia de Cartagena'],
            ['fecha' => '2026-12-08', 'nombre' => 'Inmaculada Concepción'],
            ['fecha' => '2026-12-25', 'nombre' => 'Navidad'],
        ];

        foreach ($festivos2026 as $festivo) {
            Festivo::updateOrCreate(
                ['fecha' => $festivo['fecha']],
                array_merge($festivo, ['ambito' => 'nacional'])
            );
        }
    }
}
