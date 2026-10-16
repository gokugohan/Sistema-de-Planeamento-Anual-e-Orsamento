<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolesAndPermissionsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Reset cached roles and permissions
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        // Create Permissions
        $permissions = [
            // Module 4.1: Gestão Organizacional e Usuários
            ['name' => 'manage_users', 'module' => 'organizational', 'display_name' => 'Manage Users', 'description' => 'Create, edit, and delete users'],
            ['name' => 'manage_roles', 'module' => 'organizational', 'display_name' => 'Manage Roles', 'description' => 'Assign roles to users'],
            ['name' => 'manage_directorates', 'module' => 'organizational', 'display_name' => 'Manage Directorates', 'description' => 'Create and edit directorates'],
            ['name' => 'manage_programs', 'module' => 'organizational', 'display_name' => 'Manage Programs', 'description' => 'Create and edit programs'],
            ['name' => 'view_organizational', 'module' => 'organizational', 'display_name' => 'View Organizational Structure', 'description' => 'View organizational hierarchy'],

            // Module 4.2: Planeamento Anual de Atividades (PAA)
            ['name' => 'create_activities', 'module' => 'paa', 'display_name' => 'Create Activities', 'description' => 'Create new PAA activities'],
            ['name' => 'edit_activities', 'module' => 'paa', 'display_name' => 'Edit Activities', 'description' => 'Edit draft activities'],
            ['name' => 'delete_activities', 'module' => 'paa', 'display_name' => 'Delete Activities', 'description' => 'Delete activities'],
            ['name' => 'submit_paa', 'module' => 'paa', 'display_name' => 'Submit PAA', 'description' => 'Submit PAA for approval'],
            ['name' => 'approve_paa', 'module' => 'paa', 'display_name' => 'Approve PAA', 'description' => 'Approve or reject PAA submissions'],
            ['name' => 'view_all_paa', 'module' => 'paa', 'display_name' => 'View All PAA', 'description' => 'View PAA across all directorates'],
            ['name' => 'view_paa', 'module' => 'paa', 'display_name' => 'View PAA', 'description' => 'View annual activity plans'],

            // Module 4.3: Consolidação Orçamental Ministerial
            ['name' => 'create_fiscal_envelope', 'module' => 'budget', 'display_name' => 'Create Fiscal Envelope', 'description' => 'Create new fiscal envelopes'],
            ['name' => 'edit_fiscal_envelope', 'module' => 'budget', 'display_name' => 'Edit Fiscal Envelope', 'description' => 'Edit fiscal envelopes'],
            ['name' => 'approve_fiscal_envelope', 'module' => 'budget', 'display_name' => 'Approve Fiscal Envelope', 'description' => 'Approve or reject fiscal envelopes'],
            ['name' => 'activate_fiscal_envelope', 'module' => 'budget', 'display_name' => 'Activate Fiscal Envelope', 'description' => 'Activate approved fiscal envelopes'],
            ['name' => 'confirm_budget', 'module' => 'budget', 'display_name' => 'Confirm Activity Budget', 'description' => 'Confirm the budget lines of an activity (the resource plan) before it is certified'],
            ['name' => 'view_budget', 'module' => 'budget', 'display_name' => 'View Budget', 'description' => 'View budget consolidation'],

            // Module 4.4: Monitorização da Execução Física
            ['name' => 'update_indicator_progress', 'module' => 'monitoring', 'display_name' => 'Update Indicator Progress', 'description' => 'Update progress on indicators'],
            ['name' => 'approve_progress_report', 'module' => 'monitoring', 'display_name' => 'Approve Progress Report', 'description' => 'Approve progress reports'],
            ['name' => 'verify_spending', 'module' => 'monitoring', 'display_name' => 'Verify Spending', 'description' => 'Verify the committed and paid figures reported by the units'],
            ['name' => 'view_monitoring', 'module' => 'monitoring', 'display_name' => 'View Monitoring', 'description' => 'View monitoring data'],

            // Module 4.5: Relatório: PAA
            ['name' => 'generate_reports', 'module' => 'reports', 'display_name' => 'Generate Reports', 'description' => 'Generate PAA reports'],
            ['name' => 'export_reports', 'module' => 'reports', 'display_name' => 'Export Reports', 'description' => 'Export reports to PDF/Excel'],
            ['name' => 'view_reports', 'module' => 'reports', 'display_name' => 'View Reports', 'description' => 'View generated reports'],
        ];

        foreach ($permissions as $permissionData) {
            Permission::firstOrCreate(
                ['name' => $permissionData['name']],
                $permissionData
            );
        }

        // Create Roles
        $roles = [
            [
                'name' => 'Admin',
                'display_name' => 'Administrator',
                'description' => 'Full system access with all permissions',
                'permissions' => Permission::all()->pluck('name')->toArray(), // ALL permissions
            ],
            [
                'name' => 'Minister',
                'display_name' => 'Minister',
                'description' => 'Ministry leader with approval authority',
                'permissions' => [
                    'approve_paa',
                    'approve_fiscal_envelope',
                    'approve_progress_report',
                    'view_all_paa',
                    'view_paa',
                    'view_budget',
                    'view_monitoring',
                    'view_reports',
                ],
            ],
            [
                'name' => 'Director',
                'display_name' => 'Director',
                'description' => 'Directorate director with planning and monitoring authority',
                'permissions' => [
                    'create_activities',
                    'edit_activities',
                    'submit_paa',
                    'update_indicator_progress',
                    'view_paa',
                    'view_budget',
                    'view_monitoring',
                    'view_reports',
                    'view_organizational',
                ],
            ],
            [
                'name' => 'Planning',
                'display_name' => 'Planner',
                'description' => 'Activity planner',
                'permissions' => [
                    'create_activities',
                    'edit_activities',
                    'submit_paa',
                    'view_paa',
                    'view_reports',
                ],
            ],
            [
                'name' => 'Finance',
                'display_name' => 'Finance Officer',
                'description' => 'Verifies the spending (committed and paid) that units report',
                'permissions' => [
                    'verify_spending',
                    'confirm_budget',
                    'view_paa',
                    'view_budget',
                    'view_monitoring',
                    'view_reports',
                ],
            ],
            [
                'name' => 'Viewer',
                'display_name' => 'Viewer',
                'description' => 'Read-only access to all modules',
                'permissions' => [
                    'view_paa',
                    'view_budget',
                    'view_monitoring',
                    'view_reports',
                ],
            ],
        ];

        foreach ($roles as $roleData) {
            $permissionNames = $roleData['permissions'];
            unset($roleData['permissions']);

            $role = Role::firstOrCreate(
                ['name' => $roleData['name']],
                $roleData
            );

            // Sync permissions
            $role->syncPermissions($permissionNames);
        }

        $this->command?->info('Roles and permissions (Spatie) seeded successfully!');
    }
}
