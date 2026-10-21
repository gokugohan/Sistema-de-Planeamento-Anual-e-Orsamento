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
                ['label' => 'Dashboard', 'route' => 'dashboard', 'icon' => 'layout-dashboard', 'audience' => 'wide',
                    'hint' => 'Key figures and what needs your attention'],
                ['label' => 'My work', 'route' => 'work.home', 'match' => ['work.home', 'units.show'], 'icon' => 'layout-dashboard', 'audience' => 'unit',
                    'hint' => 'Your unit\'s plan, what waits for you and the deadlines'],
            ],
        ],
        [
            'label' => 'Plan',
            'items' => [
                ['label' => 'Units', 'route' => 'units.index', 'match' => ['units.*'], 'icon' => 'building', 'audience' => 'wide',
                    'hint' => 'The action plan of any directorate, department or unit'],
                ['label' => 'My units', 'route' => 'units.index', 'icon' => 'building', 'audience' => 'unit', 'roles' => ['Director'],
                    'hint' => 'The units of your directorate and how each is doing'],
                ['label' => 'Programmes', 'route' => 'planning.programmes', 'icon' => 'layers', 'permission' => 'view_paa',
                    'hint' => 'The programmes and sub-programmes of the plan, with their activities and amounts'],
                ['label' => 'Plan cycle', 'route' => 'planning.cycle', 'icon' => 'arrow-left-right', 'permission' => 'view_paa', 'audience' => 'wide',
                    'hint' => 'Submission windows, approval and reporting of the annual plan'],
                ['label' => 'All activities', 'route' => 'activities.index', 'match' => ['activities.*'], 'icon' => 'clipboard-list', 'audience' => 'wide',
                    'hint' => 'Every activity of the plan, with its indicators and budget lines'],
                ['label' => 'My plan', 'route' => 'activities.index', 'match' => ['activities.*'], 'icon' => 'clipboard-list', 'audience' => 'unit',
                    'hint' => 'The activities of your unit, with their indicators and budget lines'],
            ],
        ],
        [
            'label' => 'Approvals',
            'roles' => ['Admin', 'Minister', 'Director', 'Planning', 'Finance'],
            'items' => [
                ['label' => 'Waiting for you', 'route' => 'approvals.index', 'match' => ['approvals.index', 'planning.paa-approval', 'monitoring.approval'], 'icon' => 'check-square',
                    'hint' => 'Activities to decide, figures to verify and plans ready to finalize'],
                ['label' => 'Budget review', 'route' => 'approvals.budget', 'icon' => 'banknote', 'permission' => 'confirm_budget',
                    'hint' => 'Confirm the budget lines of submitted activities'],
                ['label' => 'Approval lists', 'route' => 'approvals.lists', 'icon' => 'users', 'roles' => ['Admin', 'Director'],
                    'hint' => 'Name who prepares, certifies and approves the activities of a unit'],
                ['label' => 'Interim approvers', 'route' => 'approvals.delegations', 'icon' => 'user-check', 'roles' => ['Admin', 'Director'],
                    'hint' => 'Someone stands in for a director while they are away'],
            ],
        ],
        [
            'label' => 'Execute',
            'items' => [
                ['label' => 'Compile the quarter', 'route' => 'reports.compile', 'icon' => 'file-bar-chart', 'roles' => ['Admin', 'Director'],
                    'hint' => 'Turn the units\' monthly and quarterly reports into the text of the ministry report'],
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
                    'hint' => 'Reports and the final plan report'],
            ],
        ],
        [
            'label' => 'Administration',
            'roles' => ['Admin'],
            'items' => [
                ['label' => 'System Users', 'route' => 'management.users', 'icon' => 'users', 'hint' => 'Accounts, roles and permissions'],
                ['label' => 'Structure', 'route' => 'management.entities', 'icon' => 'building', 'hint' => 'Units, programmes, sub-programmes and economic categories: set them up and edit them'],
                ['label' => 'Funding sources', 'route' => 'management.funding-sources', 'icon' => 'banknote', 'hint' => 'Where the money of a budget line comes from'],
            ],
        ],
        [
            'label' => 'System',
            'roles' => ['Admin'],
            'items' => [
                ['label' => 'Settings', 'route' => 'management.settings', 'icon' => 'settings', 'hint' => 'Organisation identity and planning options'],
                ['label' => 'Optional rules', 'route' => 'management.optional-rules', 'icon' => 'settings', 'hint' => 'Rules of the planning cycle to switch on when your institution needs them'],
                ['label' => 'Reporting windows', 'route' => 'management.reporting-windows', 'icon' => 'calendar', 'hint' => 'When the plan, a month, a quarter or a revision may be submitted'],
                ['label' => 'Import Activities', 'route' => 'management.import', 'icon' => 'download', 'hint' => 'Load activities from a CSV file'],
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
        'units.show' => ['units.index', 'Unit workspace'],
        'activities.create' => ['activities.index', 'New activity'],
        'activities.edit' => ['activities.index', 'Edit activity'],
        'activities.show' => ['activities.index', 'Activity details'],
        'activities.indicators' => ['activities.index', 'Indicators'],
        'activities.budget' => ['activities.index', 'Budget lines'],
        'reports.approved-plan' => ['reports.paa', 'Final plan report'],
        'planning.paa-approval' => ['approvals.index', 'Activities to decide'],
        'monitoring.approval' => ['approvals.index', 'Verify progress'],
        'notifications.index' => [null, 'Notifications', 'Overview'],
        'profile' => [null, 'Profile', 'Overview'],
    ],
];
