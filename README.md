# laravel-vat-validator

EU BTW-nummer validatie voor Laravel. VIES als primaire bron met automatische fallbacks naar `btwzoeken.be`, `controleerbtwnummer.eu` en `btw-opzoeken.be`. Cached, geretry'd, met adres-parsing.

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
| `sources` | `['vies', 'btwzoeken', 'cbw', 'btwo']` | De volgorde waarin bronnen bevraagd worden |
| `btwzoeken.base_url` | `https://btwzoeken.be/api/v1` | Leeg maken zet die bron uit |
| `btwzoeken.key` | `null` | Optionele API-sleutel van btwzoeken.be |

Override via env: `VAT_VALIDATOR_CACHE_TTL`, `VAT_VALIDATOR_FALLBACKS`,
`VAT_VALIDATOR_BTWZOEKEN_URL`, `VAT_VALIDATOR_BTWZOEKEN_KEY`, etc.

## Bronnen

1. **VIES (EU)** — `https://ec.europa.eu/taxation_customs/vies/rest-api/...` — primaire bron, retried 2× met 200ms.
2. **btwzoeken.be** — `https://btwzoeken.be/api/v1/companies/{nummer}` — de open data van de KBO als JSON.
3. **controleerbtwnummer.eu** — fallback voor alle EU-landen wanneer VIES geen antwoord geeft.
4. **btw-opzoeken.be** — laatste redmiddel, alleen voor BE-nummers.

De volgorde ligt niet vast in de code maar in `sources`. De eerste bron die een geldig
antwoord geeft, wint; wat erachter staat, wordt niet meer bevraagd. Een bron die wegvalt,
een foutstatus geeft of begrensd wordt (429), telt als "weet het niet" — dan komt de
volgende aan de beurt in plaats van dat de hele lookup faalt.

Negatieve lookups worden niet gecached (zodat een net geactiveerd BTW-nummer niet 24u onbruikbaar blijft).

### btwzoeken.be

Deze bron geeft meer terug dan VIES: naam, adres in aparte velden, en achter dezelfde API
ook de rechtsvorm, de activiteiten en de vestigingen. Voor Belgische nummers is dat het
verschil tussen "bestaat dit" en "wie is dit".

Let op wat `valid` hier betekent. VIES zegt of een nummer VANDAAG geldig is voor
intracommunautaire handel; btwzoeken.be zegt of de onderneming in de KBO **actief** staat.
Een stopgezette onderneming komt dus als ongeldig terug en niet als onbekend — wie een
factuur nakijkt van een bedrijf dat vorig jaar ophield, hoort geen groen vinkje te krijgen.
Wie de officiële EU-bevestiging nodig heeft, houdt `vies` vooraan in `sources`.

Een sleutel is optioneel:

```dotenv
VAT_VALIDATOR_BTWZOEKEN_KEY=btwz_...
```

Zonder sleutel geldt de publieke begrenzing van btwzoeken.be (5 verzoeken per minuut en
20 per uur per IP-adres); met een sleutel 100 per uur. Een sleutel maak je aan op
`btwzoeken.be/api/sleutels`. De cache in deze package telt daarin mee: een nummer dat
vandaag al opgezocht is, kost geen tweede verzoek.

**Draait deze package IN btwzoeken.be zelf**, dan hoort `btwzoeken` uit `sources` te gaan
(of `VAT_VALIDATOR_BTWZOEKEN_URL` leeg te staan). De API daar valt voor een onbekend nummer
terug op deze validator, en een validator die die API bevraagt, is een lus.

## Cache-formaat

Resultaten gaan als platte array de cache in, niet als geserialiseerd object. Dat is bewust:
Laravel 13 zet `serializable_classes` in `config/cache.php` standaard op `false`, waardoor
objecten die uit een serialiserende store (file, redis, database, storage) komen niet meer
hersteld worden. Met een array-payload werkt de cache op elke store en op elke Laravel-versie,
zonder dat de app iets hoeft te configureren.

De payload draagt een versienummer. Wijzigt de vorm van het resultaat, dan worden oude entries
automatisch genegeerd in plaats van half gehydrateerd; `cache_prefix` aanpassen is daarvoor niet
nodig.
