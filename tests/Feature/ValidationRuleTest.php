<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;

it('laat de vat-regel slagen voor een geldig nummer', function () {
    Http::fake([
        'ec.europa.eu/*' => Http::response(viesValidResponse()),
    ]);

    $validator = Validator::make(['btw' => 'BE0123456789'], ['btw' => 'vat']);

    expect($validator->passes())->toBeTrue();
});

it('laat de vat-regel falen voor een onbekend nummer', function () {
    Http::fake([
        'ec.europa.eu/*' => Http::response(['isValid' => false]),
        'controleerbtwnummer.eu/*' => Http::response(['valid' => false]),
        'www.btw-opzoeken.be/*' => Http::response([]),
    ]);

    $validator = Validator::make(['btw' => 'BE0123456789'], ['btw' => 'vat']);

    expect($validator->passes())->toBeFalse()
        ->and($validator->errors()->first('btw'))
        ->toBe('Het BTW-nummer is niet geldig of niet gevonden.');
});

it('laat de vat-regel falen voor een niet-string waarde zonder http-call', function (mixed $value) {
    Http::fake();

    $validator = Validator::make(['btw' => $value], ['btw' => 'vat']);

    expect($validator->passes())->toBeFalse();
    Http::assertNothingSent();
})->with([
    'integer' => 123,
    'array' => [['BE0123456789']],
    'null' => null,
]);

it('slaat de vat-regel over voor een lege string, zoals Laravel dat doet voor niet-implicit regels', function () {
    Http::fake();

    $validator = Validator::make(['btw' => ''], ['btw' => 'vat']);

    expect($validator->passes())->toBeTrue();
    Http::assertNothingSent();
});
