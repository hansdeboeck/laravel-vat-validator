<?php

declare(strict_types=1);

namespace HansDeBoeck\VatValidator;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * EU VAT (BTW) lookup met VIES als primaire bron en twee fallbacks.
 *
 * Origineel ontleend aan App\Services\Btw\BtwService van Opnieuw — hier
 * geëxtraheerd zodat meerdere projecten dezelfde validator gebruiken.
 */
class VatValidator
{
    private const VIES_URL = 'https://ec.europa.eu/taxation_customs/vies/rest-api/ms/%s/vat/%s';
    private const FALLBACK_EU_URL = 'https://controleerbtwnummer.eu/api/validate/%s.json';
    private const FALLBACK_BOB_URL = 'https://www.btw-opzoeken.be/VATSearch/Search?KeyWord=%s&currentSite=www.btw-opzoeken.be';

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

    private function resolve(string $vat): VatLookupResult
    {
        $country = substr($vat, 0, 2);
        $number = substr($vat, 2);

        if ($vies = $this->viaVies($country, $number, $vat)) {
            return $vies;
        }

        if (($this->config['fallbacks_enabled'] ?? true)) {
            if ($eu = $this->viaControleerBtwNummer($vat, $country)) {
                return $eu;
            }

            if ($country === 'BE' && $bob = $this->viaBtwOpzoeken($vat, $country)) {
                return $bob;
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
