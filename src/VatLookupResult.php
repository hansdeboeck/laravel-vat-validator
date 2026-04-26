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
    public function __construct(
        public readonly bool $valid,
        public readonly ?string $vatNumber = null,
        public readonly ?string $name = null,
        public readonly ?string $countryCode = null,
        public readonly ?VatAddress $address = null,
        public readonly ?string $source = null,
        public readonly ?string $error = null,
    ) {}

    public static function invalid(string $error, ?string $vatNumber = null): self
    {
        return new self(valid: false, vatNumber: $vatNumber, error: $error);
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
        }

        return $out;
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
