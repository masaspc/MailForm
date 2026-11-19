<?php

return [
    'delivery_enabled' => filter_var(env('MAILFORM_DELIVERY_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
    'recaptcha_site_key' => env('RECAPTCHA_SITE_KEY'),
    'recaptcha_secret' => env('RECAPTCHA_SECRET'),
    'recaptcha_score' => (float) env('RECAPTCHA_SCORE', 0.5),
];
