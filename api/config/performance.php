<?php

/*
|--------------------------------------------------------------------------
| Performance measurement (docs/PERFORMANCE.md, ADR-0013)
|--------------------------------------------------------------------------
|
| Knobs for the synthetic volume seeder. Read here, never through env()
| elsewhere (ADR-0002).
|
*/

return [

    'seed' => [
        'clubs' => (int) env('PERF_CLUBS', 20),
        'members_per_club' => (int) env('PERF_MEMBERS_PER_CLUB', 1500),
    ],

];
