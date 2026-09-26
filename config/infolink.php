<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Notifications
    |--------------------------------------------------------------------------
    |
    | Quiet hours (app timezone): anything but critical insights waits until they end. The same insight
    | fingerprint is pushed at most once per dedupe window.
    |
    */

    'notifications' => [
        'quiet_hours_start' => env('INFOLINK_QUIET_HOURS_START', '22:00'),
        'quiet_hours_end' => env('INFOLINK_QUIET_HOURS_END', '08:00'),
        'dedupe_hours' => (int) env('INFOLINK_NOTIFY_DEDUPE_HOURS', 24),
    ],

];
