<?php

declare(strict_types=1);

use HansDeBoeck\VatValidator\VatValidator;
use Illuminate\Support\Facades\Http;

it('gebruikt VIES als primaire bron', function () {
    Http::fake([
        'ec.europa.eu/*' => Http::response(viesValidResponse()),
    ]);

    $result = app(VatValidator::class)->lookup('BE0123456789');

    expect($result->valid)->toBeTrue()
        ->and($result->source)->toBe('vies')
        ->and($result->name)->toBe('ACME BVBA')
        ->and($result->vatNumber)->toBe('BE0123456789')
        ->and($result->countryCode)->toBe('BE')
        ->and($result->address->city)->toBe('GENT');

    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'controleerbtwnummer.eu'));
});

it('valt terug op controleerbtwnummer.eu wanneer VIES isValid false geeft', function () {
    Http::fake([
        'ec.europa.eu/*' => Http::response(['isValid' => false]),
        'controleerbtwnummer.eu/*' => Http::response([
            'valid' => true,
            'name' => 'Fallback NV',
            'address' => [
                'street' => 'Dorpsstraat',
                'number' => '5',
                'zip_code' => '2000',
                'city' => 'ANTWERPEN',
                'country' => 'Belgie',
            ],
        ]),
    ]);

    $result = app(VatValidator::class)->lookup('BE0123456789');

    expect($result->valid)->toBeTrue()
        ->and($result->source)->toBe('cbw')
        ->and($result->name)->toBe('Fallback NV')
        ->and($result->address->zipCode)->toBe('2000');
});

it('valt terug op btw-opzoeken.be, maar alleen voor BE', function () {
    Http::fake([
        'ec.europa.eu/*' => Http::response(['isValid' => false]),
        'controleerbtwnummer.eu/*' => Http::response(['valid' => false]),
        'www.btw-opzoeken.be/*' => Http::response([[
            'VAT' => 'BE0123456789',
            'CompanyName' => 'Laatste Kans BVBA',
            'Street' => 'Molenweg',
            'StreetNumber' => '7',
            'Box' => 'B',
            'Zipcode' => '3000',
            'City' => 'LEUVEN',
            'Country' => 'Belgie',
        ]]),
    ]);

    $result = app(VatValidator::class)->lookup('BE0123456789');

    expect($result->valid)->toBeTrue()
        ->and($result->source)->toBe('btwo')
        ->and($result->address->number)->toBe('7 Box B');
});

it('slaat btw-opzoeken.be over voor niet-Belgische nummers', function () {
    Http::fake([
        'ec.europa.eu/*' => Http::response(['isValid' => false]),
        'controleerbtwnummer.eu/*' => Http::response(['valid' => false]),
        'www.btw-opzoeken.be/*' => Http::response([]),
    ]);

    $result = app(VatValidator::class)->lookup('NL123456789B01');

    expect($result->valid)->toBeFalse();
    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'btw-opzoeken.be'));
});

it('slaat alle fallbacks over wanneer fallbacks_enabled false is', function () {
    config()->set('vat-validator.fallbacks_enabled', false);
    app()->forgetInstance(VatValidator::class);

    Http::fake([
        'ec.europa.eu/*' => Http::response(['isValid' => false]),
        '*' => Http::response(['valid' => true, 'name' => 'Mag niet gebruikt worden']),
    ]);

    $result = app(VatValidator::class)->lookup('BE0123456789');

    expect($result->valid)->toBeFalse();
    Http::assertSentCount(1);
});

it('negeert een fallback die een foutstatus teruggeeft', function () {
    Http::fake([
        'ec.europa.eu/*' => Http::response(['isValid' => false]),
        'controleerbtwnummer.eu/*' => Http::response(['valid' => true, 'name' => 'Foutpagina'], 500),
        'www.btw-opzoeken.be/*' => Http::response([], 503),
    ]);

    $result = app(VatValidator::class)->lookup('BE0123456789');

    expect($result->valid)->toBeFalse()
        ->and($result->error)->toBe('Invalid VAT number');
});

it('negeert VIES wanneer die een foutstatus teruggeeft', function () {
    Http::fake([
        'ec.europa.eu/*' => Http::response(viesValidResponse(), 503),
        'controleerbtwnummer.eu/*' => Http::response(['valid' => true, 'name' => 'Fallback NV']),
    ]);

    $result = app(VatValidator::class)->lookup('BE0123456789');

    expect($result->source)->toBe('cbw');
});
