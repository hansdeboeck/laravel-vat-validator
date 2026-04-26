<?php

return [

    // Cache TTL voor positieve lookups, in seconden. Default 24 uur.
    'cache_ttl' => env('VAT_VALIDATOR_CACHE_TTL', 86400),

    // Schakel cache uit voor tests of debugging.
    'cache_enabled' => env('VAT_VALIDATOR_CACHE_ENABLED', true),

    // Prefix voor cache-keys. Wijzig om bestaande cache te invalideren.
    'cache_prefix' => env('VAT_VALIDATOR_CACHE_PREFIX', 'vat:'),

    // HTTP-timeout per request in seconden. VIES is soms traag, dus
    // niet onder de 5 zetten.
    'http_timeout' => env('VAT_VALIDATOR_HTTP_TIMEOUT', 6),

    // Activeer fallbacks (controleerbtwnummer.eu + btw-opzoeken.be) wanneer
    // VIES niet beschikbaar is of geen resultaat geeft.
    'fallbacks_enabled' => env('VAT_VALIDATOR_FALLBACKS', true),

];
