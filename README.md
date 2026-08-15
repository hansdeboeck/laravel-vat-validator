# laravel-vat-validator

EU BTW-nummer validatie voor Laravel. VIES als primaire bron met automatische fallbacks naar `controleerbtwnummer.eu` en `btw-opzoeken.be`. Cached, geretry'd, met adres-parsing.

## Requirements

- PHP 8.3 of hoger
- Laravel 12 of 13

Laravel 11 wordt niet meer ondersteund: die versie is sinds 12 maart 2026 volledig end-of-life,
ook voor security fixes.

## Installatie

Lokaal (path-repository) — voeg toe aan je app's `composer.json`:

```json
{
    "require": {
        "hansdeboeck/laravel-vat-validator": "*"
    },
    "repositories": [
        { "type": "path", "url": "../laravel-vat-validator" }
    ]
}
```

Daarna:

```bash
composer require hansdeboeck/laravel-vat-validator:*
php artisan vendor:publish --tag=vat-validator-config   # optioneel
```

## Gebruik

```php
use HansDeBoeck\VatValidator\VatValidator;

$result = app(VatValidator::class)->lookup('BE0405622220');

if ($result->valid) {
    echo $result->name;                     // "ALDI HOLDING NV"
    echo $result->vatNumber;                // "BE0405622220"
    echo $result->countryCode;              // "BE"
    echo $result->source;                   // "vies" | "cbw" | "btwo"
    echo $result->address?->street;
    echo $result->address?->zipCode;
    echo $result->address?->city;
} else {
    echo $result->error;
}
```

### Validation rule

```php
$request->validate([
    'vat' => ['required', 'vat'],
]);
```

Vraagt VIES om de input te valideren — dezelfde semantiek als `lookup()`.

### Array access (legacy)

Het result-object implementeert `ArrayAccess`, zodat code die een array verwachtte blijft werken:

```php
$result['valid'];
$result['vat_number'];
$result['address']['city'];
```

## Configuratie

[`config/vat-validator.php`](config/vat-validator.php):

| Key | Default | Doel |
|---|---|---|
| `cache_ttl` | 86400 (24u) | Hoe lang positieve lookups gecached worden |
| `cache_enabled` | true | Voor tests/debug uit te zetten |
| `cache_prefix` | `vat:` | Wijzig om bestaande cache te invalideren |
| `http_timeout` | 6s | Per HTTP-request, niet onder 5 |
| `fallbacks_enabled` | true | Schakel niet-VIES bronnen uit |

Override via env: `VAT_VALIDATOR_CACHE_TTL`, `VAT_VALIDATOR_FALLBACKS`, etc.

## Bronnen

1. **VIES (EU)** — `https://ec.europa.eu/taxation_customs/vies/rest-api/...` — primaire bron, retried 2× met 200ms.
2. **controleerbtwnummer.eu** — fallback voor alle EU-landen wanneer VIES geen antwoord geeft.
3. **btw-opzoeken.be** — laatste redmiddel, alleen voor BE-nummers.

Negatieve lookups worden niet gecached (zodat een net geactiveerd BTW-nummer niet 24u onbruikbaar blijft).

## Cache-formaat

Resultaten gaan als platte array de cache in, niet als geserialiseerd object. Dat is bewust:
Laravel 13 zet `serializable_classes` in `config/cache.php` standaard op `false`, waardoor
objecten die uit een serialiserende store (file, redis, database, storage) komen niet meer
hersteld worden. Met een array-payload werkt de cache op elke store en op elke Laravel-versie,
zonder dat de app iets hoeft te configureren.

De payload draagt een versienummer. Wijzigt de vorm van het resultaat, dan worden oude entries
automatisch genegeerd in plaats van half gehydrateerd; `cache_prefix` aanpassen is daarvoor niet
nodig.
