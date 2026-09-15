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

/**
 * Antwoord zoals /api/v1/companies/{nummer} van btwzoeken.be het geeft.
 *
 * Ingekort tot de velden die deze package leest: de echte fiche draagt er
 * meer (activiteiten, vestigingen, hoedanigheden), en die horen niet in een
 * fixture die alleen de mapping bewaakt.
 */
function btwzoekenValidResponse(array $overrides = []): array
{
    return ['data' => array_merge([
        'vat' => 'BE0123456789',
        'enterprise_number' => '0123456789',
        'name' => 'Frituur Het Vosje',
        'active' => true,
        'addresses' => [[
            'street' => 'Vlasmarkt',
            'number' => '12',
            'box' => 'B',
            'postal_code' => '9000',
            'city' => 'Gent',
            'country' => 'BE',
        ]],
    ], $overrides)];
}
