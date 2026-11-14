<?php

declare(strict_types=1);

namespace HansDeBoeck\VatValidator;

use ArrayAccess;
use JsonSerializable;

/**
 * Resultaat van een BTW-lookup. Property-access voor nieuwe code,
 * ArrayAccess voor backwards-compatibility met legacy array-stijl.
 *
 * Snake_case sleutels in array-vorm (`vat_number`, `country_code`,
 * `zip_code`) zodat code die het naar JSON serialiseert dezelfde shape
 * houdt als de oorspronkelijke BtwService.
 */
final class VatLookupResult implements ArrayAccess, JsonSerializable
{
    /**
     * Verhoog dit zodra de properties wijzigen; bestaande cache-entries
     * worden dan automatisch genegeerd.
     */
    private const CACHE_VERSION = 1;

    public function __construct(
        public readonly bool $valid,
        public readonly ?string $vatNumber = null,
        public readonly ?string $name = null,
        public readonly ?string $countryCode = null,
        public readonly ?VatAddress $address = null,
        public readonly ?string $source = null,
        public readonly ?string $error = null,
        public readonly bool $unavailable = false,
    ) {}

    public static function invalid(string $error, ?string $vatNumber = null): self
    {
        return new self(valid: false, vatNumber: $vatNumber, error: $error);
    }

    /**
     * Geen enkele bron gaf een antwoord: VIES lag eruit, de fallbacks ook.
     *
     * DIT IS GEEN ONGELDIG NUMMER, en dat onderscheid is de reden van bestaan
     * van deze methode. Wie "ongeldig" leest, weigert de klant; wie
     * "onbereikbaar" leest, probeert het straks opnieuw. Vroeger kwamen beide
     * als 'Invalid VAT number' terug, en dan werd een bestaand bedrijf
     * afgewezen omdat een lidstaat even niet antwoordde.
     *
     * `valid` blijft false: wie alleen naar `valid` kijkt, laat zo'n nummer
     * niet door. Wie het verschil wil maken, kijkt naar `unavailable`.
     */
    public static function unavailable(?string $vatNumber = null): self
    {
        return new self(
            valid: false,
            vatNumber: $vatNumber,
            error: 'VAT service unavailable',
            unavailable: true,
        );
    }

    public function toArray(): array
    {
        $out = [
            'valid' => $this->valid,
        ];

        if ($this->valid) {
            $out['source'] = $this->source;
            $out['name'] = $this->name;
            $out['vat_number'] = $this->vatNumber;
            $out['address'] = $this->address?->toArray();
        } else {
            if ($this->vatNumber !== null) {
                $out['vat_number'] = $this->vatNumber;
            }
            if ($this->error !== null) {
                $out['error'] = $this->error;
            }
            // Alleen wanneer het zo is: zo blijft de vorm voor een gewoon
            // ongeldig nummer precies wat hij was.
            if ($this->unavailable) {
                $out['unavailable'] = true;
            }
        }

        return $out;
    }

    /**
     * Verliesvrije vorm voor de cache. Bewust los van toArray(): die is
     * publiek, snake_case en lossy (laat countryCode weg bij een geldig
     * resultaat) en mag om backwards-compat redenen niet wijzigen.
     *
     * Er gaat een objectvorm de cache in noch uit: Laravel 13 zet
     * `serializable_classes` standaard op false, waardoor geserialiseerde
     * objecten er niet meer uit komen.
     */
    public function toCacheArray(): array
    {
        return [
            'v' => self::CACHE_VERSION,
            'valid' => $this->valid,
            'vatNumber' => $this->vatNumber,
            'name' => $this->name,
            'countryCode' => $this->countryCode,
            'address' => $this->address?->toCacheArray(),
            'source' => $this->source,
            'error' => $this->error,
        ];
    }

    /**
     * Geeft null bij een entry uit een andere versie van deze klasse, zodat
     * oude cache-entries genegeerd worden in plaats van half gehydrateerd.
     */
    public static function fromCacheArray(array $data): ?self
    {
        if (($data['v'] ?? null) !== self::CACHE_VERSION) {
            return null;
        }

        $address = $data['address'] ?? null;

        return new self(
            valid: (bool) ($data['valid'] ?? false),
            vatNumber: $data['vatNumber'] ?? null,
            name: $data['name'] ?? null,
            countryCode: $data['countryCode'] ?? null,
            address: is_array($address) ? VatAddress::fromCacheArray($address) : null,
            source: $data['source'] ?? null,
            error: $data['error'] ?? null,
        );
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    public function offsetExists(mixed $offset): bool
    {
        return array_key_exists($offset, $this->toArray());
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->toArray()[$offset] ?? null;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        throw new \LogicException('VatLookupResult is immutable.');
    }

    public function offsetUnset(mixed $offset): void
    {
        throw new \LogicException('VatLookupResult is immutable.');
    }
}
