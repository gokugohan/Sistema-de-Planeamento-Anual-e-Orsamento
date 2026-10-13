<?php

/**
 * Reference lists used when planning activities. They are plain lists so an institution can adapt
 * them without touching code; the stored value is the text itself.
 */
return [
    // Requests slower than this many milliseconds are logged with their query count (0 turns it off).
    'slow_request_ms' => (int) env('SLOW_REQUEST_MS', 500),
    'slow_request_channel' => env('SLOW_REQUEST_CHANNEL'), // null: the default log channel

    // Where an activity is carried out. Timor-Leste: 12 municipalities, Ataúro and the special
    // administrative region of Oé-Cusse Ambeno (RAEOA); "Nationwide" for activities with no single place.
    'locations' => [
        'Nationwide',
        'Aileu', 'Ainaro', 'Ataúro', 'Baucau', 'Bobonaro', 'Cova Lima', 'Dili', 'Ermera', 'Lautém',
        'Liquiçá', 'Manatuto', 'Manufahi', 'Oé-Cusse Ambeno (RAEOA)', 'Viqueque',
    ],
];
