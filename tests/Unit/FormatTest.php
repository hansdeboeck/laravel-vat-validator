<?php

declare(strict_types=1);

use HansDeBoeck\VatValidator\VatFormat;

it('keurt een Belgisch nummer met een juist controlegetal goed', function (string $input) {
    expect(VatFormat::check($input))->toMatchArray([
        'vatNumber' => 'BE0403170701',
        'countryCode' => 'BE',
        'validFormat' => true,
        'checksum' => true,
        'error' => null,
    ]);
})->with(['BE0403170701', 'be 0403.170.701', '0403170701', '403170701']);

it('keurt een Belgisch nummer met een fout controlegetal af', function () {
    expect(VatFormat::check('BE0403170702'))->toMatchArray([
        'validFormat' => false,
        'checksum' => false,
        'error' => 'Invalid check digits',
    ]);
});

it('rekent ook de Belgische nummers na die met een 1 beginnen', function () {
    expect(VatFormat::check('BE1000000021')['checksum'])->toBeTrue()
        ->and(VatFormat::check('BE1000000097')['checksum'])->toBeFalse();
});

it('kent de elfproef en mod 97 voor Nederland', function (string $input, bool $ok) {
    expect(VatFormat::check($input)['checksum'])->toBe($ok);
})->with([
    'elfproef' => ['NL004495445B01', true],
    'btw-id sinds 2020' => ['NL000099998B57', true],
    'geen van beide' => ['NL123456789B01', false],
]);

it('laat checksum leeg voor landen zonder nagerekend controlegetal', function () {
    expect(VatFormat::check('FR40303265045'))->toMatchArray([
        'validFormat' => true,
        'checksum' => null,
    ]);
});

it('wijst een fout formaat per land af', function (string $input, string $error) {
    expect(VatFormat::check($input))->toMatchArray([
        'validFormat' => false,
        'error' => $error,
    ]);
})->with([
    ['DE12345678', 'Invalid format for DE'],
    ['NL123456789', 'Invalid format for NL'],
    ['XX123456789', 'Unknown country code'],
    ['GR123456789', 'Use EL as the country code for Greece'],
    ['', 'Unknown country code'],
]);
