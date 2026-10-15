<?php

/**
 * Single source of truth for the application's navigation.
 *
 * It drives three things so they can never disagree: the sidebar, the
 * breadcrumbs in the top bar, and the "Go to…" quick-jump (Ctrl+K).
 *
 * Group / item keys
 *   label       translated with __()
 *   route       route name (items without children)
 *   match       route-name patterns that mark the item active (default: [route])
 *   icon        icon key understood by <x-sidebar-link>
 *   hint        one line shown in the quick-jump
 *   roles       user must hold ANY of these roles
 *   permission  user must hold this permission
 *   feature     'testing' = only when the PAA simulator is switched on in Settings
 *   children    sub-items (one level)
 *
 * `pages` lists routes that are not menu entries (detail and create screens)
 * so their breadcrumb reads  Group › Parent › This page.
 */
return [
    'groups' => [
        [
            'label' => 'Overview',
            'items' => [
                ['label' => 'Dashboard', 'route' => 'dashboard', 'icon' => 'layout-dashboard',
                    'hint' => 'Key figures and what needs your attention'],
            ],
        ],
        [
            'label' => 'Plan',
            'items' => [
                ['label' => 'PAA Activities', 'route' => 'activities.index', 'match' => ['activities.*'], 'icon' => 'clipboard-list',
                    'hint' => 'Plan activities with indicators and budget lines'],
            ],
        ],
        [
            'label' => 'Approvals',
            'roles' => ['Admin', 'Minister', 'Director', 'Planning', 'Finance'],
            'items' => [
                ['label' => 'Approvals', 'route' => 'approvals.index', 'match' => ['approvals.*', 'planning.paa-approval', 'monitoring.approval'], 'icon' => 'check-square',
                    'hint' => 'Everything waiting for you: activities to decide, figures to verify, the plan to finalize'],
            ],
        ],
        [
            'label' => 'Budget',
            'items' => [
                ['label' => 'Budget Cycle', 'route' => 'planning.budget-cycle', 'icon' => 'arrow-left-right', 'permission' => 'view_all_paa',
                    'hint' => 'Ceiling, CRO submission and decision, State Budget law'],
                ['label' => 'Fiscal Envelope', 'icon' => 'wallet-cards', 'permission' => 'view_all_paa', 'hint' => 'The amount received for the year',
                    'children' => [
                        ['label' => 'Current Envelope', 'route' => 'budget.envelope.current', 'icon' => 'circle',
                            'hint' => 'The envelope in force and its distribution'],
                        ['label' => 'Envelope List', 'route' => 'budget.envelope.list', 'icon' => 'list',
                            'hint' => 'All fiscal years'],
                    ]],
                ['label' => 'Budget Allocation', 'route' => 'budget.allocation', 'icon' => 'layers', 'permission' => 'view_all_paa',
                    'hint' => 'What each unit, programme and category received'],
                ['label' => 'Allocation Review', 'route' => 'planning.director-review', 'icon' => 'user-check', 'roles' => ['Admin', 'Minister', 'Director', 'Planning'],
                    'hint' => 'Review how the envelope was allocated'],
            ],
        ],
        [
            'label' => 'Execute',
            'items' => [
                ['label' => 'Report execution', 'route' => 'monitoring.report', 'icon' => 'banknote', 'permission' => 'view_monitoring',
                    'hint' => 'Report what an activity spent and achieved in a quarter'],
            ],
        ],
        [
            'label' => 'Insights',
            'items' => [
                ['label' => 'Performance', 'route' => 'monitoring.index', 'icon' => 'file-bar-chart',
                    'hint' => 'Paid and delivered against the plan, by quarter and programme'],
                ['label' => 'Reporting', 'route' => 'reports.paa', 'match' => ['reports.*'], 'icon' => 'file-bar-chart', 'permission' => 'view_all_paa',
                    'hint' => 'Reports and the approved plan for the CRO'],
            ],
        ],
        [
            'label' => 'Administration',
            'roles' => ['Admin'],
            'items' => [
                ['label' => 'System Users', 'route' => 'management.users', 'icon' => 'users', 'hint' => 'Accounts, roles and permissions'],
                ['label' => 'Structure', 'route' => 'management.entities', 'icon' => 'building', 'hint' => 'Units, programmes, subprogrammes and economic categories, with their funding'],
                ['label' => 'Import Activities', 'route' => 'management.import', 'icon' => 'download', 'hint' => 'Load activities from a CSV file'],
                ['label' => 'Funding sources', 'route' => 'management.funding-sources', 'icon' => 'banknote', 'hint' => 'Where the money of a budget line comes from'],
                ['label' => 'Reporting windows', 'route' => 'management.reporting-windows', 'icon' => 'calendar', 'hint' => 'When each month and quarter may be reported'],
                ['label' => 'Settings', 'route' => 'management.settings', 'icon' => 'settings', 'hint' => 'Organisation identity and planning options'],
                ['label' => 'Audit Log', 'route' => 'management.audit', 'icon' => 'shield-check', 'hint' => 'Who changed what, and when'],
                ['label' => 'Sample Data', 'route' => 'management.sample-data', 'icon' => 'flask-conical', 'hint' => 'Load or purge the demo dataset'],
            ],
        ],
        [
            'label' => 'Testing Zone',
            'feature' => 'testing',
            'items' => [
                ['label' => 'Simulator', 'route' => 'testing.index', 'match' => ['testing.*'], 'icon' => 'flask-conical',
                    'hint' => 'Practise planning on test data'],
            ],
        ],
    ],

    // Screens that are not menu entries: route => [parent route, breadcrumb label]
    'pages' => [
        'activities.create' => ['activities.index', 'New activity'],
        'activities.edit' => ['activities.index', 'Edit activity'],
        'activities.show' => ['activities.index', 'Activity details'],
        'activities.indicators' => ['activities.index', 'Indicators'],
        'activities.budget' => ['activities.index', 'Budget lines'],
        'reports.approved-plan' => ['reports.paa', 'Approved plan for CRO'],
        'planning.paa-approval' => ['approvals.index', 'Activities to decide'],
        'monitoring.approval' => ['approvals.index', 'Verify progress'],
        'planning.fiscal' => ['budget.envelope.current', 'Fiscal authority'],
        'notifications.index' => [null, 'Notifications', 'Overview'],
        'profile' => [null, 'Profile', 'Overview'],
    ],
];
