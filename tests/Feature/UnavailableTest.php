<?php

declare(strict_types=1);

use HansDeBoeck\VatValidator\VatValidator;
use Illuminate\Support\Facades\Http;

/*
| Ongeldig en onbereikbaar zijn twee verschillende antwoorden.
|
| Wie "ongeldig" krijgt, weigert de klant. Wie "onbereikbaar" krijgt, probeert
| later opnieuw. Deze tests bewaken dat een lidstaat die even niet antwoordt
| geen bestaand bedrijf afwijst.
*/

it('meldt onbereikbaar wanneer geen enkele bron antwoordt', function () {
    Http::fake([
        'ec.europa.eu/*' => Http::response([], 503),
        'btwzoeken.be/*' => Http::response(['message' => 'Te veel verzoeken.'], 429),
        'controleerbtwnummer.eu/*' => Http::response([], 500),
        'www.btw-opzoeken.be/*' => Http::response([], 503),
    ]);

    $result = app(VatValidator::class)->lookup('BE0123456789');

    expect($result->valid)->toBeFalse()
        ->and($result->unavailable)->toBeTrue()
        ->and($result->error)->toBe('VAT service unavailable')
        ->and($result->toArray())->toMatchArray(['valid' => false, 'unavailable' => true]);
});

it('leest MS_UNAVAILABLE van VIES niet als een ongeldig nummer', function () {
    config()->set('vat-validator.fallbacks_enabled', false);
    app()->forgetInstance(VatValidator::class);

    Http::fake([
        'ec.europa.eu/*' => Http::response(['isValid' => false, 'userError' => 'MS_UNAVAILABLE']),
    ]);

    expect(app(VatValidator::class)->lookup('BE0123456789')->unavailable)->toBeTrue();
});

it('leest INVALID van VIES wel als een ongeldig nummer', function () {
    config()->set('vat-validator.fallbacks_enabled', false);
    app()->forgetInstance(VatValidator::class);

    Http::fake([
        'ec.europa.eu/*' => Http::response(['isValid' => false, 'userError' => 'INVALID']),
    ]);

    $result = app(VatValidator::class)->lookup('BE0123456789');

    expect($result->unavailable)->toBeFalse()
        ->and($result->error)->toBe('Invalid VAT number')
        ->and($result->toArray())->not->toHaveKey('unavailable');
});

it('meldt ongeldig wanneer een fallback antwoordt terwijl VIES eruit ligt', function () {
    Http::fake([
        'ec.europa.eu/*' => Http::response(['isValid' => false, 'userError' => 'TIMEOUT']),
        'btwzoeken.be/*' => Http::response([], 404),
        'controleerbtwnummer.eu/*' => Http::response([], 500),
        'www.btw-opzoeken.be/*' => Http::response([], 503),
    ]);

    $result = app(VatValidator::class)->lookup('BE0123456789');

    expect($result->unavailable)->toBeFalse()
        ->and($result->error)->toBe('Invalid VAT number');
});

it('meldt onbereikbaar bij een netwerkfout', function () {
    config()->set('vat-validator.fallbacks_enabled', false);
    app()->forgetInstance(VatValidator::class);

    Http::fake([
        'ec.europa.eu/*' => fn () => throw new \Illuminate\Http\Client\ConnectionException('time-out'),
    ]);

    expect(app(VatValidator::class)->lookup('BE0123456789')->unavailable)->toBeTrue();
});

it('cachet een onbereikbaar resultaat niet', function () {
    config()->set('vat-validator.fallbacks_enabled', false);
    app()->forgetInstance(VatValidator::class);

    // Twee keer 503: de client probeert VIES zelf een tweede keer.
    Http::fakeSequence('ec.europa.eu/*')
        ->push([], 503)
        ->push([], 503)
        ->push(viesValidResponse());

    $validator = app(VatValidator::class);

    expect($validator->lookup('BE0123456789')->unavailable)->toBeTrue()
        ->and($validator->lookup('BE0123456789')->valid)->toBeTrue();
});

it('laat de vat-regel falen wanneer niets antwoordt', function () {
    config()->set('vat-validator.fallbacks_enabled', false);
    app()->forgetInstance(VatValidator::class);

    Http::fake(['ec.europa.eu/*' => Http::response([], 503)]);

    expect(\Illuminate\Support\Facades\Validator::make(['btw' => 'BE0123456789'], ['btw' => 'vat'])->passes())
        ->toBeFalse();
});
