<?php

return [

    /*
     * The season the app considers current. Everything user-facing defaults to
     * this; sync commands can target any year.
     */
    'season' => (int) env('CFB_SEASON', 2025),

    /*
     * Contest scheduling, slate eligibility, and lock times are all evaluated
     * here — never in UTC. A CFB season spans EDT and EST, so this must stay a
     * named zone rather than a fixed offset.
     */
    'timezone' => env('CFB_TIMEZONE', 'America/New_York'),

];
