<?php

declare(strict_types=1);

namespace HansDeBoeck\VatValidator;

use ArrayAccess;
use JsonSerializable;

/**
 * Adres geretourneerd door de validator. Implementeert ArrayAccess voor
 * backwards-compatibility met code die een array verwachtte
 * (bv. `$result['address']['street']`).
 */
final class VatAddress implements ArrayAccess, JsonSerializable
{
    public function __construct(
        public readonly ?string $street = null,
        public readonly ?string $number = null,
        public readonly ?string $zipCode = null,
        public readonly ?string $city = null,
        public readonly ?string $country = null,
        public readonly ?string $countryCode = null,
    ) {}

    public function toArray(): array
    {
        return [
            'street' => $this->street,
            'number' => $this->number,
            'zip_code' => $this->zipCode,
            'city' => $this->city,
            'country' => $this->country,
            'countryCode' => $this->countryCode,
        ];
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
        throw new \LogicException('VatAddress is immutable.');
    }

    public function offsetUnset(mixed $offset): void
    {
        throw new \LogicException('VatAddress is immutable.');
    }
}
