<?php

return [
    'recaptcha_site_key' => env('RECAPTCHA_SITE_KEY'),
    'recaptcha_secret' => env('RECAPTCHA_SECRET'),
    'recaptcha_score' => (float) env('RECAPTCHA_SCORE', 0.5),
];
