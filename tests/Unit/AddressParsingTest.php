<?php

declare(strict_types=1);

use HansDeBoeck\VatValidator\VatValidator;
use Illuminate\Support\Facades\Http;

function lookupWithViesAddress(?string $address): HansDeBoeck\VatValidator\VatAddress
{
    Http::fake([
        'ec.europa.eu/*' => Http::response(viesValidResponse(['address' => $address])),
    ]);

    return app(VatValidator::class)->lookup('BE0123456789')->address;
}

it('splitst een Belgisch adres in straat, nummer, postcode en gemeente', function () {
    $address = lookupWithViesAddress("Kerkstraat 12\n9000 GENT");

    expect($address->street)->toBe('Kerkstraat')
        ->and($address->number)->toBe('12')
        ->and($address->zipCode)->toBe('9000')
        ->and($address->city)->toBe('GENT')
        ->and($address->country)->toStartWith('Belg')
        ->and($address->countryCode)->toBe('BE');
});

it('herkent een busnummer als deel van het huisnummer', function () {
    $address = lookupWithViesAddress("Molenweg 7 bus 3\n3000 LEUVEN");

    expect($address->street)->toBe('Molenweg')
        ->and($address->number)->toBe('7 bus 3');
});

it('herkent een huisnummer met letter', function () {
    $address = lookupWithViesAddress("Dorpsstraat 15A\n2000 ANTWERPEN");

    expect($address->street)->toBe('Dorpsstraat')
        ->and($address->number)->toBe('15A');
});

it('verwerkt een Nederlandse postcode met letters', function () {
    $address = lookupWithViesAddress("Hoofdstraat 1\n1234 AB AMSTERDAM");

    expect($address->street)->toBe('Hoofdstraat')
        ->and($address->number)->toBe('1')
        ->and($address->zipCode)->toBe('1234')
        ->and($address->city)->toBe('AB AMSTERDAM');
});

it('laat het nummer leeg wanneer de straat er geen heeft', function () {
    $address = lookupWithViesAddress("Industriepark\n8500 KORTRIJK");

    expect($address->street)->toBe('Industriepark')
        ->and($address->number)->toBeNull();
});

it('laat de straat leeg wanneer alleen de postcoderegel er staat', function () {
    $address = lookupWithViesAddress('9000 GENT');

    expect($address->street)->toBeNull()
        ->and($address->zipCode)->toBe('9000')
        ->and($address->city)->toBe('GENT');
});

it('valt terug op alleen straat wanneer er geen postcode in staat', function () {
    $address = lookupWithViesAddress('Postbus 100');

    expect($address->street)->toBe('Postbus 100')
        ->and($address->zipCode)->toBeNull()
        ->and($address->city)->toBeNull();
});

it('geeft een leeg adres terug bij een leeg of ontbrekend VIES-adres', function (?string $input) {
    $address = lookupWithViesAddress($input);

    expect($address->street)->toBeNull()
        ->and($address->number)->toBeNull()
        ->and($address->zipCode)->toBeNull()
        ->and($address->city)->toBeNull()
        ->and($address->countryCode)->toBe('BE');
})->with([null, '', '   ', '---']);
