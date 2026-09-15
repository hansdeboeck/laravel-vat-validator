<?php

declare(strict_types=1);

namespace HansDeBoeck\VatValidator;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * EU VAT (BTW) lookup met VIES als primaire bron en drie fallbacks.
 *
 * Origineel ontleend aan App\Services\Btw\BtwService van Opnieuw — hier
 * geëxtraheerd zodat meerdere projecten dezelfde validator gebruiken.
 *
 * De bronnen en hun volgorde staan in config/vat-validator.php; daar staat ook
 * waarom de beste bron per project verschilt.
 */
class VatValidator
{
    private const VIES_URL = 'https://ec.europa.eu/taxation_customs/vies/rest-api/ms/%s/vat/%s';
    private const FALLBACK_EU_URL = 'https://controleerbtwnummer.eu/api/validate/%s.json';
    private const FALLBACK_BOB_URL = 'https://www.btw-opzoeken.be/VATSearch/Search?KeyWord=%s&currentSite=www.btw-opzoeken.be';
    private const BTWZOEKEN_BASE = 'https://btwzoeken.be/api/v1';

    /**
     * De bronvolgorde wanneer de config er geen opgeeft.
     *
     * Dezelfde lijst als in config/vat-validator.php, en die staat hier een
     * tweede keer omdat een app die de config niet publiceerde -- of een
     * validator die met een lege array opgebouwd wordt, zoals in een test --
     * anders geen enkele bron zou bevragen.
     *
     * @var list<string>
     */
    private const DEFAULT_SOURCES = ['vies', 'btwzoeken', 'cbw', 'btwo'];

    public function __construct(
        private readonly CacheRepository $cache,
        private readonly array $config = [],
    ) {}

    public function lookup(string $vat): VatLookupResult
    {
        $vat = $this->normalize($vat);

        if (! preg_match('/^[A-Z]{2}[0-9A-Z]{8,12}$/', $vat)) {
            return VatLookupResult::invalid('Invalid VAT number format', $vat);
        }

        $cacheKey = ($this->config['cache_prefix'] ?? 'vat:') . $vat;

        if (! empty($this->config['cache_enabled'] ?? true)) {
            $cached = $this->cache->get($cacheKey);

            if (is_array($cached) && $hit = VatLookupResult::fromCacheArray($cached)) {
                return $hit;
            }

            // Entry geschreven door een oudere versie van deze package, die
            // het resultaat nog als object cachete. Verloopt vanzelf.
            if ($cached instanceof VatLookupResult) {
                return $cached;
            }
        }

        $result = $this->resolve($vat);

        if ($result->valid && ! empty($this->config['cache_enabled'] ?? true)) {
            $this->cache->put($cacheKey, $result->toCacheArray(), $this->config['cache_ttl'] ?? 86400);
        }

        return $result;
    }

    /**
     * De bronnen aflopen tot er een antwoordt.
     *
     * DE VOLGORDE KOMT UIT DE CONFIG en staat niet meer hier vast. Er zijn er
     * vier, en welke de beste is, hangt af van wat de app ermee doet: wie
     * Belgische nummers nakijkt heeft aan btwzoeken.be het rijkste antwoord,
     * wie een intracommunautaire factuur moet verantwoorden heeft VIES nodig.
     * Zie config/vat-validator.php voor de afweging.
     *
     * Een onbekende naam in die lijst wordt overgeslagen en niet gemeld als
     * fout: een typefout in de config hoort geen lookup te laten falen die
     * verder gewoon kan doorgaan.
     */
    private function resolve(string $vat): VatLookupResult
    {
        $country = substr($vat, 0, 2);
        $number = substr($vat, 2);

        $sources = $this->config['sources'] ?? self::DEFAULT_SOURCES;
        $fallbacks = (bool) ($this->config['fallbacks_enabled'] ?? true);

        foreach ($sources as $source) {
            // Alles behalve VIES is een fallback. Staan die uit, dan blijft
            // alleen VIES over -- ook als de lijst hierboven anders zegt.
            if (! $fallbacks && $source !== 'vies') {
                continue;
            }

            $result = match ($source) {
                'vies' => $this->viaVies($country, $number, $vat),
                'btwzoeken' => $this->viaBtwzoeken($vat, $country),
                'cbw' => $this->viaControleerBtwNummer($vat, $country),
                // btw-opzoeken.be kent alleen Belgische nummers.
                'btwo' => $country === 'BE' ? $this->viaBtwOpzoeken($vat, $country) : null,
                default => null,
            };

            if ($result !== null) {
                return $result;
            }
        }

        return VatLookupResult::invalid('Invalid VAT number', $vat);
    }

    private function viaVies(string $country, string $number, string $vat): ?VatLookupResult
    {
        try {
            $response = Http::timeout($this->config['http_timeout'] ?? 6)
                ->retry(2, 200, throw: false)
                ->acceptJson()
                ->get(sprintf(self::VIES_URL, $country, $number));
        } catch (Throwable $e) {
            Log::warning('VIES lookup failed', ['vat' => $vat, 'error' => $e->getMessage()]);
            return null;
        }

        if (! $response->ok()) {
            return null;
        }

        $data = $response->json();
        if (! is_array($data) || empty($data['isValid'])) {
            return null;
        }

        [$street, $streetNumber, $zip, $city] = $this->parseViesAddress(
            $this->cleanText($data['address'] ?? null) ?? ''
        );

        return new VatLookupResult(
            valid: true,
            vatNumber: $vat,
            name: $this->cleanText($data['name'] ?? null),
            countryCode: $country,
            address: new VatAddress(
                street: $street,
                number: $streetNumber,
                zipCode: $zip,
                city: $city,
                country: $this->countryNameFromCode($country),
                countryCode: $country,
            ),
            source: 'vies',
        );
    }

    /**
     * btwzoeken.be — de open data van de KBO, als JSON.
     *
     * WAAROM DEZE BRON ERBIJ HOORT. VIES antwoordt met een naam en een adres in
     * één tekstblok dat per lidstaat anders geschreven is; deze bron geeft de
     * velden apart terug, mét de rechtsvorm en de hoedanigheden erbij. Voor een
     * Belgisch nummer is dat het verschil tussen "bestaat dit" en "wie is dit".
     *
     * WAT `valid` HIER BETEKENT, en dat wijkt af van de andere bronnen. VIES
     * zegt of een nummer VANDAAG geldig is voor intracommunautaire handel;
     * deze bron zegt of de onderneming in de KBO actief staat. Een stopgezette
     * onderneming staat er dus nog in, en komt hier als ongeldig terug — niet
     * als "onbekend". Dat is bewust: wie een factuur nakijkt van een bedrijf
     * dat vorig jaar ophield, hoort geen groen vinkje te krijgen.
     *
     * DE SLEUTEL IS OPTIONEEL. Zonder sleutel geldt de publieke begrenzing van
     * btwzoeken.be en komt er bij te snel bevragen een 429 terug; die wordt
     * hier behandeld als "deze bron weet het niet", zodat de volgende bron aan
     * de beurt komt in plaats van dat de hele lookup faalt.
     */
    private function viaBtwzoeken(string $vat, string $country): ?VatLookupResult
    {
        $base = rtrim((string) ($this->config['btwzoeken']['base_url'] ?? self::BTWZOEKEN_BASE), '/');

        // Een lege base_url is hoe een app deze bron uitzet zonder de hele
        // `sources`-lijst te moeten overschrijven.
        if ($base === '') {
            return null;
        }

        $key = $this->config['btwzoeken']['key'] ?? null;

        try {
            $request = Http::timeout($this->config['http_timeout'] ?? 6)->acceptJson();

            if (is_string($key) && $key !== '') {
                $request = $request->withToken($key);
            }

            $response = $request->get($base . '/companies/' . urlencode($vat));
        } catch (Throwable $e) {
            Log::warning('btwzoeken lookup failed', ['vat' => $vat, 'error' => $e->getMessage()]);

            return null;
        }

        if (! $response->ok()) {
            return null;
        }

        $data = $response->json('data');

        if (! is_array($data) || empty($data['active'])) {
            return null;
        }

        // De API geeft alle adressen terug; het eerste is de maatschappelijke
        // zetel. Deze package draagt er één, net als bij de andere bronnen.
        $address = is_array($data['addresses'] ?? null) ? ($data['addresses'][0] ?? null) : null;

        return new VatLookupResult(
            valid: true,
            vatNumber: $data['vat'] ?? $vat,
            name: $this->cleanText($data['name'] ?? null),
            countryCode: $country,
            address: is_array($address) ? new VatAddress(
                street: $address['street'] ?? null,
                number: $this->joinNumberAndBox($address['number'] ?? null, $address['box'] ?? null),
                zipCode: $address['postal_code'] ?? null,
                city: $address['city'] ?? null,
                country: $this->countryNameFromCode($address['country'] ?? $country),
                countryCode: $address['country'] ?? $country,
            ) : null,
            source: 'btwzoeken',
        );
    }

    private function viaControleerBtwNummer(string $vat, string $country): ?VatLookupResult
    {
        try {
            $response = Http::timeout($this->config['http_timeout'] ?? 6)
                ->acceptJson()
                ->get(sprintf(self::FALLBACK_EU_URL, $vat));
        } catch (Throwable $e) {
            Log::warning('controleerbtwnummer lookup failed', ['vat' => $vat, 'error' => $e->getMessage()]);
            return null;
        }

        if (! $response->ok()) {
            return null;
        }

        $data = $response->json();
        if (! is_array($data) || empty($data['valid'])) {
            return null;
        }

        return new VatLookupResult(
            valid: true,
            vatNumber: $vat,
            name: $data['name'] ?? null,
            countryCode: $country,
            address: new VatAddress(
                street: $data['address']['street'] ?? null,
                number: $data['address']['number'] ?? null,
                zipCode: $data['address']['zip_code'] ?? null,
                city: $data['address']['city'] ?? null,
                country: $data['address']['country'] ?? $this->countryNameFromCode($country),
                countryCode: $country,
            ),
            source: 'cbw',
        );
    }

    private function viaBtwOpzoeken(string $vat, string $country): ?VatLookupResult
    {
        try {
            $response = Http::timeout($this->config['http_timeout'] ?? 6)
                ->acceptJson()
                ->get(sprintf(self::FALLBACK_BOB_URL, $vat));
        } catch (Throwable $e) {
            Log::warning('btw-opzoeken lookup failed', ['vat' => $vat, 'error' => $e->getMessage()]);
            return null;
        }

        if (! $response->ok()) {
            return null;
        }

        $data = $response->json();
        if (! is_array($data) || count($data) !== 1) {
            return null;
        }

        $row = $data[0];
        $houseNumber = trim(
            ($row['StreetNumber'] ?? '') . (! empty($row['Box']) ? ' Box ' . $row['Box'] : '')
        );

        return new VatLookupResult(
            valid: true,
            vatNumber: $row['VAT'] ?? $vat,
            name: $row['CompanyName'] ?? null,
            countryCode: $country,
            address: new VatAddress(
                street: $row['Street'] ?? null,
                number: $houseNumber !== '' ? $houseNumber : null,
                zipCode: $row['Zipcode'] ?? null,
                city: $row['City'] ?? null,
                country: $row['Country'] ?? $this->countryNameFromCode($country),
                countryCode: $country,
            ),
            source: 'btwo',
        );
    }

    private function parseViesAddress(string $address): array
    {
        if ($address === '') {
            return [null, null, null, null];
        }

        $lines = array_values(array_filter(
            array_map('trim', preg_split('/\r?\n/', $address)),
            fn ($l) => $l !== ''
        ));

        if ($lines === []) {
            return [null, null, null, null];
        }

        $street = null;
        $streetNumber = null;
        $zip = null;
        $city = null;

        foreach ($lines as $line) {
            if (preg_match('/^(\d{4,5})\s+(.+)$/u', $line, $m)) {
                $zip = $m[1];
                $city = trim($m[2]);
                break;
            }
        }

        if ($zip !== null && ! preg_match('/^\d{4,5}\s/', $lines[0])) {
            [$street, $streetNumber] = $this->splitStreetAndNumber($lines[0]);
        } elseif ($zip === null) {
            $street = $lines[0];
        }

        return [$street, $streetNumber, $zip, $city];
    }

    private function splitStreetAndNumber(string $line): array
    {
        $pattern = '/^(.*?)\s+(\d+[A-Za-z]?(?:[\/\s-]?\d+[A-Za-z]?)?(?:\s+(?:bus|box|bte|bte\.)\s+\S+)?)$/iu';
        if (preg_match($pattern, trim($line), $m)) {
            return [trim($m[1]), trim($m[2])];
        }
        return [trim($line), null];
    }

    /**
     * Huisnummer en busnummer als één veld, zoals VatAddress ze draagt.
     *
     * De KBO houdt ze apart; deze package heeft één `number`. Een bus die
     * verloren gaat, is een pakket dat bij de buren ligt.
     */
    private function joinNumberAndBox(?string $number, ?string $box): ?string
    {
        $number = trim((string) $number);
        $box = trim((string) $box);

        $joined = trim($number . ($box !== '' ? ' bus ' . $box : ''));

        return $joined === '' ? null : $joined;
    }

    private function normalize(string $vat): string
    {
        $vat = strtoupper(preg_replace('/[\s.\-]/', '', $vat));

        if (preg_match('/^\d{9,10}$/', $vat)) {
            $vat = 'BE' . str_pad($vat, 10, '0', STR_PAD_LEFT);
        }

        return $vat;
    }

    private function cleanText(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim(preg_replace('/[ \t]+/', ' ', $value));
        return ($value === '' || $value === '---') ? null : $value;
    }

    private function countryNameFromCode(string $code): ?string
    {
        return match ($code) {
            'BE' => 'België',
            'NL' => 'Nederland',
            'FR' => 'Frankrijk',
            'DE' => 'Duitsland',
            'LU' => 'Luxemburg',
            'AT' => 'Oostenrijk',
            'ES' => 'Spanje',
            'IT' => 'Italië',
            'PT' => 'Portugal',
            'PL' => 'Polen',
            default => null,
        };
    }
}
