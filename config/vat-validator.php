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

    // Activeer fallbacks (btwzoeken.be, controleerbtwnummer.eu, btw-opzoeken.be)
    // wanneer VIES niet beschikbaar is of geen resultaat geeft.
    'fallbacks_enabled' => env('VAT_VALIDATOR_FALLBACKS', true),

    /*
    | De volgorde waarin de bronnen bevraagd worden.
    |
    | EEN LIJST EN GEEN VASTE VOLGORDE IN DE CODE, omdat de beste bron per
    | project verschilt. Wie vooral Belgische nummers nakijkt, heeft aan
    | btwzoeken.be een vollediger antwoord dan aan VIES -- daar staat de
    | rechtsvorm, de activiteit en de vestigingen bij. Wie de officiele
    | EU-bevestiging nodig heeft (voor een factuur zonder btw binnen de EU),
    | houdt VIES vooraan: dat is de enige bron waar een belastingdienst naar
    | kijkt.
    |
    | De eerste bron die een geldig antwoord geeft, wint. Wat erna staat, wordt
    | niet meer bevraagd.
    |
    | Alles behalve 'vies' telt als fallback: staat `fallbacks_enabled` op
    | false, dan blijft alleen VIES over, ongeacht wat hier staat.
    */
    'sources' => [
        'vies',
        'btwzoeken',
        'cbw',
        'btwo',
    ],

    /*
    | btwzoeken.be -- de open data van de KBO, als JSON.
    |
    | LET OP BIJ DE SITE ZELF. Draait deze package IN btwzoeken.be, dan hoort
    | 'btwzoeken' uit `sources` te gaan: de API daar valt voor een onbekend
    | nummer terug op deze validator, en een validator die de API bevraagt die
    | op de validator terugvalt, is een lus.
    |
    | DE SLEUTEL IS OPTIONEEL. Zonder sleutel geldt de publieke begrenzing
    | (5 verzoeken per minuut, 20 per uur per IP-adres); met een sleutel
    | 100 per uur. Een sleutel maak je aan op btwzoeken.be/api/sleutels.
    | De cache in deze package telt daarin mee: een nummer dat vandaag al
    | opgezocht is, kost geen tweede verzoek.
    */
    'btwzoeken' => [
        'base_url' => env('VAT_VALIDATOR_BTWZOEKEN_URL', 'https://btwzoeken.be/api/v1'),
        'key' => env('VAT_VALIDATOR_BTWZOEKEN_KEY'),
    ],

];
