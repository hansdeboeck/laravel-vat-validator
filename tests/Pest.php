<?php

declare(strict_types=1);

use HansDeBoeck\VatValidator\Tests\TestCase;

uses(TestCase::class)->in(__DIR__);

/**
 * Antwoord zoals de VIES REST-API het geeft voor een geldig nummer.
 */
function viesValidResponse(array $overrides = []): array
{
    return array_merge([
        'isValid' => true,
        'name' => 'ACME BVBA',
        'address' => "Kerkstraat 12\n9000 GENT",
    ], $overrides);
}
