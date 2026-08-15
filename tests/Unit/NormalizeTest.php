<?php

declare(strict_types=1);

use HansDeBoeck\VatValidator\VatValidator;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::fake([
        'ec.europa.eu/*' => Http::response(viesValidResponse()),
    ]);
});

it('prefixt een kaal nummer met BE en vult links aan met nullen', function (string $input) {
    $result = app(VatValidator::class)->lookup($input);

    expect($result->vatNumber)->toBe('BE0123456789');

    Http::assertSent(fn ($request) => str_contains($request->url(), '/ms/BE/vat/0123456789'));
})->with([
    '0123456789',
    '123456789',
    '0123.456.789',
    '0123 456 789',
    'BE 0123.456.789',
    'be0123456789',
]);

it('wijst een nummer met een ongeldig formaat af zonder http-call', function (string $input) {
    $result = app(VatValidator::class)->lookup($input);

    expect($result->valid)->toBeFalse()
        ->and($result->error)->toBe('Invalid VAT number format');

    Http::assertNothingSent();
})->with([
    '',
    'BE',
    'BE123',
    '12345',
    'BE0123456789012345',
    '!!!',
]);

it('accepteert buitenlandse nummers met letters', function () {
    $result = app(VatValidator::class)->lookup('nl 1234.56789B01');

    expect($result->vatNumber)->toBe('NL123456789B01')
        ->and($result->countryCode)->toBe('NL');
});
