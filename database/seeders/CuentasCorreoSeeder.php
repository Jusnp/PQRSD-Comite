<?php

namespace Database\Seeders;

use App\Models\Aseguradora;
use App\Models\CuentaCorreo;
use App\Models\Sede;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;

class CuentasCorreoSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->environment('production')) {
            return;
        }

        $uraba = Sede::where('codigo', 'URABA')->first();
        $call = Sede::where('codigo', 'CALL')->first();

        $cuentas = [
            [
                'direccion' => 'gestionsaviasalud@menteplena.com.co',
                'descripcion' => 'Gestión Savia Salud',
                'aseguradora' => 'SAVIA',
                'sede' => $uraba?->id,
                'area_responsable' => 'Gestión al usuario',
                'credential_ref' => 'MAIL_CRED_GESTIONSAVIA',
            ],
            [
                'direccion' => 'gestornuevaeps@menteplena.com.co',
                'descripcion' => 'Gestor Nueva EPS',
                'aseguradora' => 'NUEVAEPS',
                'credential_ref' => 'MAIL_CRED_NUEVAEPS',
            ],
            [
                'direccion' => 'recepcionuraba@menteplena.com.co',
                'descripcion' => 'Recepción Urabá',
                'sede' => $uraba?->id,
                'area_responsable' => 'Recepción',
                'credential_ref' => 'MAIL_CRED_RECEPCIONURABA',
            ],
            [
                'direccion' => 'pqrsepssura@menteplena.com.co',
                'descripcion' => 'PQR EPS Sura',
                'aseguradora' => 'SURA',
                'credential_ref' => 'MAIL_CRED_PQRSURA',
            ],
            [
                'direccion' => 'asistadministrativauraba@menteplena.com.co',
                'descripcion' => 'Asistencia administrativa Urabá',
                'sede' => $uraba?->id,
                'area_responsable' => 'Administrativa',
                'credential_ref' => 'MAIL_CRED_ASISTURABA',
            ],
            [
                'direccion' => 'gestor2@menteplena.com.co',
                'descripcion' => 'Gestor 2 — Coosalud (etiqueta operativa; no asumir función automática)',
                'aseguradora' => 'COOSALUD',
                'credential_ref' => 'MAIL_CRED_GESTOR2',
            ],
            [
                'direccion' => 'gestorcoomeva@menteplena.com.co',
                'descripcion' => 'Gestor Coomeva',
                'aseguradora' => 'COOMEVA',
                'credential_ref' => 'MAIL_CRED_COOMEVA',
            ],
            [
                'direccion' => 'gestor5@menteplena.com.co',
                'descripcion' => 'Gestor 5 — Sura Póliza (etiqueta operativa)',
                'aseguradora' => 'SURA_POLIZA',
                'credential_ref' => 'MAIL_CRED_GESTOR5',
            ],
            [
                'direccion' => 'callcenterarl@menteplena.com.co',
                'descripcion' => 'Call center ARL Sura',
                'aseguradora' => 'ARL_SURA',
                'sede' => $call?->id,
                'area_responsable' => 'Call center',
                'credential_ref' => 'MAIL_CRED_CALLCENTERARL',
            ],
            [
                'direccion' => 'gestionalusuario@menteplena.com.co',
                'descripcion' => 'Gestión al usuario',
                'area_responsable' => 'Gestión al usuario',
                'credential_ref' => 'MAIL_CRED_GESTIONALUSUARIO',
            ],
            [
                'direccion' => 'atencionalusuario@menteplena.com.co',
                'descripcion' => 'Atención al usuario',
                'area_responsable' => 'Atención al usuario',
                'credential_ref' => 'MAIL_CRED_ATENCIONALUSUARIO',
            ],
            [
                'direccion' => 'pqrsd-central@menteplena.test',
                'descripcion' => 'Buzón central de pruebas (reenvíos/copias). Sustituir por cuenta real cuando TI confirme.',
                'es_buzon_central' => true,
                'area_responsable' => 'PQRSD',
                'credential_ref' => 'MAIL_CRED_CENTRAL',
            ],
        ];

        foreach ($cuentas as $data) {
            $aseguradoraId = null;
            if (! empty($data['aseguradora'])) {
                $aseguradoraId = Aseguradora::where('codigo', $data['aseguradora'])->value('id');
            }

            CuentaCorreo::updateOrCreate(
                ['direccion' => $data['direccion']],
                [
                    'descripcion' => $data['descripcion'],
                    'aseguradora_id' => $aseguradoraId,
                    'sede_id' => $data['sede'] ?? null,
                    'area_responsable' => $data['area_responsable'] ?? null,
                    'activo' => true,
                    'es_buzon_central' => $data['es_buzon_central'] ?? false,
                    // tipo_buzon desconocido hasta confirmación Workspace (no asumir 11 buzones independientes).
                    'tipo_buzon' => $data['tipo_buzon'] ?? 'desconocido',
                    'buzon_canonico' => $data['buzon_canonico'] ?? null,
                    'tipo_integracion' => 'mock',
                    'credential_ref' => $data['credential_ref'],
                    'estado_conexion' => 'pendiente_credenciales',
                ]
            );
        }

        $this->escribirInboxMock();
    }

    private function escribirInboxMock(): void
    {
        $path = config('pqrsd_correo.mock_inbox');
        File::ensureDirectoryExists(dirname($path));

        // En testing siempre regenerar fixtures; en local solo si no existen.
        if (File::exists($path) && ! app()->environment('testing')) {
            return;
        }

        $fixtures = [
            [
                'uid' => '1001',
                'message_id' => '<mock-nueva-001@paciente.test>',
                'account' => 'gestionsaviasalud@menteplena.com.co',
                'from' => 'ciudadano.prueba@example.test',
                'from_name' => 'Ciudadano Prueba',
                'to' => 'gestionsaviasalud@menteplena.com.co',
                'subject' => 'Queja por demora en atención',
                'body_text' => "Buenos días,\nPresento una queja formal por demora en la asignación de cita.\nDatos ficticios para prueba.",
                'date' => now('America/Bogota')->subHours(2)->toIso8601String(),
                'attachments' => [
                    [
                        'nombre' => 'soporte-prueba.txt',
                        'mime' => 'text/plain',
                        'contenido' => 'Archivo de prueba sin datos reales.',
                    ],
                ],
            ],
            [
                'uid' => '1002',
                'message_id' => '<mock-ruido-001@marketing.test>',
                'account' => 'atencionalusuario@menteplena.com.co',
                'from' => 'newsletter@marketing.test',
                'to' => 'atencionalusuario@menteplena.com.co',
                'subject' => 'Oferta semanal — unsubscribe aquí',
                'body_text' => 'Newsletter automática. Para unsubscribe haga clic.',
                'date' => now('America/Bogota')->subHour()->toIso8601String(),
            ],
            [
                'uid' => '1003',
                'message_id' => '<mock-central-001@eps.test>',
                'account' => 'pqrsd-central@menteplena.test',
                'from' => 'remitente.eps@example.test',
                'to' => 'pqrsd-central@menteplena.test',
                'original_recipient' => 'gestorcoomeva@menteplena.com.co',
                'subject' => 'Remisión PQRSD — solicitud de información',
                'body_text' => 'Se remite petición de un afiliado (datos ficticios) para gestión.',
                'date' => now('America/Bogota')->subMinutes(30)->toIso8601String(),
                'raw_headers' => "X-Original-To: gestorcoomeva@menteplena.com.co\nDelivered-To: pqrsd-central@menteplena.test",
            ],
        ];

        File::put($path, json_encode($fixtures, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }
}
