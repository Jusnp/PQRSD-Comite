<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Roles operativos de producción: solo admin y auxiliar_pqrsd.
 *
 * Roles legacy (lider_pqrsd, responsable_area, auditor, superadministrador)
 * se conservan para compatibilidad de pruebas. No asignarlos a operadores reales:
 * - lider_pqrsd / superadministrador: amplitud similar a operación plena (riesgo si quedan asignados).
 * - responsable_area: solo casos asignados (sin view_all / sin correo / sin catálogos).
 * - auditor: lectura y reportes; sin radicar, clasificar, asignar ni responder.
 */
class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $permissions = [
            'dashboard.view',
            'usuarios.manage',
            'catalogos.manage',
            'sedes.manage',
            'aseguradoras.manage',
            'canales.manage',
            'tipos.manage',
            'motivos.manage',
            'areas.manage',
            'reglas_sla.manage',
            'pqrsd.view',
            'pqrsd.view_all',
            'pqrsd.create',
            'pqrsd.create_manual',
            'pqrsd.update',
            'pqrsd.classify',
            'pqrsd.assign',
            'pqrsd.manage',
            'pqrsd.close',
            'auditoria.view',
            'reportes.view',
            'correos.manage',
            'correos.review',
            'correos.reply',
        ];

        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission);
        }

        // Rol operativo principal
        $admin = Role::findOrCreate('admin');
        $admin->syncPermissions(Permission::all());

        // Alias legacy de admin (pruebas / instalaciones previas)
        $super = Role::findOrCreate('superadministrador');
        $super->syncPermissions(Permission::all());

        // Auxiliar: operación sobre expedientes existentes. Sin radicación manual ni configuración crítica.
        $auxiliar = Role::findOrCreate('auxiliar_pqrsd');
        $auxiliarDeny = [
            'usuarios.manage',
            'catalogos.manage',
            'sedes.manage',
            'aseguradoras.manage',
            'canales.manage',
            'tipos.manage',
            'motivos.manage',
            'areas.manage',
            'reglas_sla.manage',
            'correos.manage',
            'pqrsd.create',
            'pqrsd.create_manual',
        ];
        $auxiliar->syncPermissions(
            Permission::all()
                ->reject(fn (Permission $p) => in_array($p->name, $auxiliarDeny, true))
                ->values()
        );

        // Roles legacy (no visibles en operación diaria; conservados para tests)
        $lider = Role::findOrCreate('lider_pqrsd');
        $lider->syncPermissions([
            'dashboard.view',
            'catalogos.manage',
            'sedes.manage',
            'aseguradoras.manage',
            'canales.manage',
            'tipos.manage',
            'motivos.manage',
            'areas.manage',
            'reglas_sla.manage',
            'pqrsd.view',
            'pqrsd.view_all',
            'pqrsd.create',
            'pqrsd.create_manual',
            'pqrsd.update',
            'pqrsd.classify',
            'pqrsd.assign',
            'pqrsd.manage',
            'pqrsd.close',
            'auditoria.view',
            'reportes.view',
            'correos.manage',
            'correos.review',
            'correos.reply',
        ]);

        $responsable = Role::findOrCreate('responsable_area');
        $responsable->syncPermissions([
            'dashboard.view',
            'pqrsd.view',
            'pqrsd.update',
            'pqrsd.manage',
        ]);

        $auditor = Role::findOrCreate('auditor');
        $auditor->syncPermissions([
            'dashboard.view',
            'pqrsd.view',
            'pqrsd.view_all',
            'auditoria.view',
            'reportes.view',
        ]);
    }
}
