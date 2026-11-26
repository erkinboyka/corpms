<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use App\Models\RolePermission;
use App\Shared\Permissions\PermissionMatrix;
use Illuminate\Database\Seeder;

/**
 * RBAC сид: permissions + roles + роль-permission карта со scope.
 */
class RbacSeeder extends Seeder
{
    public function run(): void
    {
        // --- permissions ---
        foreach (PermissionMatrix::all() as $slug => [$name, $group]) {
            Permission::updateOrCreate(
                ['slug' => $slug],
                ['name' => $name, 'group' => $group],
            );
        }

        $rules = self::rules();
        $pIds = Permission::query()->pluck('id', 'slug');

        // --- roles ---
        foreach ($rules['roles'] as $role) {
            Role::updateOrCreate(
                ['slug' => $role],
                [
                    'name' => $rules['roleNames'][$role],
                    'is_system' => true,
                    'description' => $rules['roleNames'][$role],
                ],
            );
        }

        $rIds = Role::query()->pluck('id', 'slug');

        // --- role_permissions ---
        foreach ($rules['matrix'] as $roleSlug => $permScopes) {
            foreach ($permScopes as $permSlug => $scope) {
                RolePermission::updateOrCreate(
                    ['role_id' => $rIds[$roleSlug], 'permission_id' => $pIds[$permSlug]],
                    ['scope' => $scope],
                );
            }
        }
    }

    public static function rules(): array
    {
        $everything = array_keys(PermissionMatrix::all());

        // super admin — все права company (плюс безусловный bypass в PermissionService)
        $super = [];
        foreach ($everything as $slug) {
            $super[$slug] = 'company';
        }

        $matrix = [
            'super_admin' => $super,

            'company_director' => [
                'chat.view' => 'company', 'chat.send' => 'company', 'chat.manage' => 'company',
                'chat.groups.create' => 'company', 'chat.channels.create' => 'company',
                'lms.view' => 'company', 'lms.manage' => 'company', 'lms.teach' => 'company', 'lms.join' => 'company',
                'companies.view' => 'company', 'departments.view' => 'company',
                'departments.manage' => 'company', 'positions.view' => 'company', 'positions.manage' => 'company',
                'org_chart.view' => 'company', 'structure.manage' => 'company',
                'employees.view' => 'company', 'employees.view_props' => 'company', 'employees.manage' => 'company',
                'onboarding.view' => 'company', 'onboarding.manage' => 'company',
                'schedules.view' => 'company', 'schedules.manage' => 'company', 'holidays.manage' => 'company',
                'leaves.view' => 'company', 'leaves.approve' => 'company',
                'attendance.view' => 'company', 'attendance.live.view' => 'company',
                'attendance.timesheet.view' => 'company', 'attendance.timesheet.export' => 'company',
                'attendance.anomalies.view' => 'company', 'attendance.anomalies.manage' => 'company',
                'attendance.correct' => 'company', 'attendance.dashboard.view' => 'company',
                'attendance.offsite.view' => 'company', 'attendance.offsite.request' => 'own',
                'attendance.offsite.approve' => 'company',
                'biometry.devices.view' => 'company', 'biometry.enroll' => 'company',
                'biometry.dashboard.view' => 'company', 'biometry.events.view' => 'company',
                'biometry.privacy.manage' => 'company', 'biometry.rfid.view' => 'company', 'biometry.rfid.manage' => 'company',
                'tasks.view' => 'company', 'tasks.create' => 'company', 'tasks.assign' => 'company',
                'tasks.assign_department' => 'company', 'tasks.manage' => 'company', 'tasks.reassign' => 'company',
                'documents.view' => 'company', 'documents.create' => 'company',
                'documents.approve' => 'company', 'documents.reject' => 'company',
                'signatures.view' => 'company', 'signatures.request' => 'company', 'signatures.sign' => 'company',
                'signatures.revoke' => 'company', 'signatures.verify' => 'company',
                'workflows.view' => 'company', 'workflows.manage' => 'company',
                'leaves.request' => 'own', 'biometry.profiles.manage' => 'company',
                'analytics.view' => 'company', 'analytics.ai.use' => 'company',
                'bumi.view' => 'company', 'bumi.manage' => 'company',
                'reports.view' => 'company', 'reports.export' => 'company',
                'payroll.view' => 'company', 'payroll.approve' => 'company',
                'inventory.view' => 'company', 'inventory.manage' => 'company', 'inventory.move' => 'company',
                'purchases.view' => 'company', 'purchases.manage' => 'company',
                'crm.view' => 'company', 'crm.manage' => 'company', 'crm.deals.manage' => 'company',
                'finance.view' => 'company', 'finance.manage' => 'company', 'finance.invoices.manage' => 'company',
                'accounting.view' => 'company', 'accounting.manage' => 'company',
                'projects.view' => 'company', 'projects.manage' => 'company',
                'bi.view' => 'company', 'bi.manage' => 'company',
                'audit.view' => 'company', 'settings.view' => 'company', 'settings.manage' => 'company',
                'search.global' => 'company', 'documents.templates.manage' => 'company',
                'documents.templates.view' => 'company',
            ],

            'deputy_director' => [
                'chat.view' => 'company', 'chat.send' => 'company',
                'chat.groups.create' => 'company', 'chat.channels.create' => 'company',
                'lms.view' => 'company', 'lms.manage' => 'company', 'lms.teach' => 'company', 'lms.join' => 'company',
                'companies.view' => 'company', 'departments.view' => 'company',
                'org_chart.view' => 'company', 'employees.view' => 'company', 'employees.view_props' => 'company',
                'onboarding.view' => 'company', 'onboarding.manage' => 'company',
                'schedules.view' => 'company', 'leaves.view' => 'company',
                'attendance.view' => 'company', 'attendance.live.view' => 'company',
                'attendance.timesheet.view' => 'company', 'attendance.dashboard.view' => 'company',
                'attendance.offsite.view' => 'company', 'attendance.offsite.request' => 'own',
                'attendance.offsite.approve' => 'company',
                'tasks.view' => 'company', 'tasks.create' => 'company', 'tasks.assign' => 'company',
                'tasks.manage' => 'company',
                'documents.view' => 'company', 'documents.approve' => 'company',
                'documents.reject' => 'company',
                'leaves.request' => 'own',
                'signatures.view' => 'company', 'signatures.sign' => 'company',
                'analytics.view' => 'company', 'bumi.view' => 'company', 'bumi.manage' => 'company',
                'reports.view' => 'company',
                'inventory.view' => 'company', 'purchases.view' => 'company',
                'crm.view' => 'company', 'crm.manage' => 'company', 'crm.deals.manage' => 'company',
                'finance.view' => 'company', 'finance.manage' => 'company', 'finance.invoices.manage' => 'company',
                'accounting.view' => 'company', 'accounting.manage' => 'company',
                'projects.view' => 'company', 'projects.manage' => 'company',
                'bi.view' => 'company', 'bi.manage' => 'company',
                'search.global' => 'company',
            ],

            'department_director' => [
                'chat.view' => 'department', 'chat.send' => 'department', 'chat.manage' => 'department',
                'chat.groups.create' => 'department',
                'lms.view' => 'department', 'lms.manage' => 'department', 'lms.teach' => 'department', 'lms.join' => 'department',
                'departments.view' => 'department', 'org_chart.view' => 'company',
                'employees.view' => 'department', 'employees.view_props' => 'department',
                'onboarding.view' => 'department',
                'schedules.view' => 'department', 'leaves.view' => 'department', 'leaves.approve' => 'department',
                'attendance.view' => 'department', 'attendance.timesheet.view' => 'department',
                'attendance.anomalies.view' => 'department', 'attendance.anomalies.manage' => 'department',
                'attendance.correct' => 'department', 'attendance.corrections.approve' => 'department', 'attendance.dashboard.view' => 'department',
                'attendance.offsite.view' => 'department', 'attendance.offsite.request' => 'own',
                'attendance.offsite.approve' => 'department',
                'tasks.view' => 'department', 'tasks.create' => 'department',
                'tasks.assign' => 'department', 'tasks.manage' => 'department', 'tasks.reassign' => 'department', 'tasks.delegate' => 'department',
                'biometry.rfid.view' => 'department', 'biometry.rfid.manage' => 'department',
                'documents.view' => 'department', 'documents.create' => 'department', 'documents.edit' => 'department',
                'documents.submit' => 'department', 'documents.approve' => 'department', 'documents.reject' => 'department',
                'leaves.request' => 'own',
                'payroll.view' => 'department',
                'tasks.assign_department' => 'department',
                'signatures.view' => 'department', 'signatures.sign' => 'department', 'signatures.verify' => 'department',
                'analytics.view' => 'department', 'bumi.view' => 'company', 'reports.view' => 'department',
                'inventory.view' => 'company', 'purchases.view' => 'company',
                'crm.view' => 'company',
                'finance.view' => 'company',
                'accounting.view' => 'company',
                'projects.view' => 'company',
                'bi.view' => 'company',
                'search.global' => 'department',
            ],

            'hr_manager' => [
                'chat.view' => 'company', 'chat.send' => 'company', 'chat.manage' => 'company',
                'chat.groups.create' => 'company', 'chat.channels.create' => 'company',
                'lms.view' => 'company', 'lms.manage' => 'company', 'lms.teach' => 'company', 'lms.join' => 'company',
                'departments.view' => 'company', 'positions.view' => 'company',
                'org_chart.view' => 'company',
                'employees.view' => 'company', 'employees.view_props' => 'company',
                'employees.manage' => 'company', 'employees.roles' => 'company',
                'onboarding.view' => 'company', 'onboarding.manage' => 'company',
                'schedules.view' => 'company', 'schedules.manage' => 'company',
                'holidays.manage' => 'company',
                'leaves.view' => 'company', 'leaves.approve' => 'company',
                'attendance.view' => 'company', 'attendance.live.view' => 'company',
                'attendance.timesheet.view' => 'company', 'attendance.timesheet.export' => 'company',
                'attendance.anomalies.view' => 'company', 'attendance.anomalies.manage' => 'company',
                'attendance.correct' => 'company',
                'attendance.corrections.approve' => 'company', 'attendance.dashboard.view' => 'company',
                'attendance.offsite.view' => 'company', 'attendance.offsite.request' => 'own',
                'attendance.offsite.approve' => 'company',
                'biometry.dashboard.view' => 'company', 'biometry.enroll' => 'company',
                'biometry.profiles.manage' => 'company', 'biometry.events.view' => 'company',
                'biometry.rfid.view' => 'company', 'biometry.rfid.manage' => 'company',
                'tasks.view' => 'company', 'reports.view' => 'company', 'reports.export' => 'company',
                'analytics.view' => 'company',
                'payroll.view' => 'company', 'payroll.manage' => 'company',
                'inventory.view' => 'company',
            ],

            'document_manager' => [
                'chat.view' => 'company', 'chat.send' => 'company',
                'chat.groups.create' => 'company',
                'documents.view' => 'company', 'documents.create' => 'company',
                'documents.edit' => 'company', 'documents.submit' => 'company',
                'documents.archive' => 'company',
                'documents.templates.view' => 'company', 'documents.templates.manage' => 'company',
                'documents.categories.manage' => 'company',
                'workflows.view' => 'company', 'workflows.manage' => 'company',
                'signatures.view' => 'company', 'signatures.request' => 'company', 'signatures.verify' => 'company',
                'reports.view' => 'company', 'analytics.view' => 'company',
                'lms.view' => 'company', 'lms.join' => 'company',
            ],

            'employee' => [
                'chat.view' => 'own', 'chat.send' => 'own',
                'lms.view' => 'own', 'lms.join' => 'own',
                'tasks.view' => 'own', 'tasks.comment' => 'own', 'tasks.delegate' => 'own',
                'attendance.view' => 'own', 'attendance.timesheet.view' => 'own',
                'attendance.correct' => 'own', 'attendance.timesheet.export' => 'own',
                'attendance.offsite.view' => 'own', 'attendance.offsite.request' => 'own',
                'leaves.request' => 'own', 'leaves.view' => 'own',
                'documents.view' => 'own', 'signatures.view' => 'own', 'signatures.sign' => 'own',
            ],
        ];

        return [
            'roles' => [
                'super_admin', 'company_director', 'deputy_director',
                'department_director', 'hr_manager', 'document_manager', 'employee',
            ],
            'roleNames' => [
                'super_admin' => 'Super Admin',
                'company_director' => 'Директор компании',
                'deputy_director' => 'Заместитель директора',
                'department_director' => 'Директор отдела',
                'hr_manager' => 'HR-менеджер',
                'document_manager' => 'Менеджер документооборота',
                'employee' => 'Сотрудник',
            ],
            'matrix' => $matrix,
        ];
    }
}
