<?php

declare(strict_types=1);

return [
    // Legal deadlines (Ley 2/2023, art. 9.2). Do not raise them without legal advice.
    'deadlines' => [
        'acknowledgement_days' => 7,
        'resolution_months' => 3,
        'extension_months' => 3,
        'warn_acknowledgement_days' => 2,
        'warn_resolution_days' => 14,
    ],

    'retention' => [
        // Art. 32.4: content of reports with no investigation started is anonymised after 3 months.
        'uninvestigated_days' => 90,
        // Anonymise the content of closed reports after N days. null keeps it until the registry expires.
        'closed_content_days' => null,
        // Art. 26.2: the registry entry is never kept longer than 10 years.
        'registry_years' => 10,
    ],

    // Allowed categories. Empty means free text.
    'categories' => [],

    // E-mail addresses that receive deadline alerts. The alerts never include report content.
    'notify' => [],

    'ability' => 'whistleblower.manage',

    'routes' => [
        'enabled' => true,
        'prefix' => 'whistleblower',
        'public_middleware' => ['api', 'throttle:whistleblower'],
        'manage_middleware' => ['api', 'auth', 'can:whistleblower.manage'],
        'throttle_per_minute' => 10,
    ],
];
