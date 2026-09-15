<?php

declare(strict_types=1);

use HansDeBoeck\VatValidator\VatValidator;
use Illuminate\Support\Facades\Http;

/**
 * btwzoeken.be als bron.
 *
 * Wat hier bewaakt wordt, is niet dat de mapping klopt -- dat is een handvol
 * sleutels -- maar dat deze bron zich gedraagt als een BRON en niet als een
 * afhankelijkheid: valt ze weg, geeft ze een 429 of staat ze uit, dan komt de
 * volgende aan de beurt en faalt de lookup niet.
 */
function fakeGeenVies(): array
{
    return ['ec.europa.eu/*' => Http::response(['isValid' => false])];
}

it('gebruikt btwzoeken.be wanneer VIES niets weet', function () {
    Http::fake(fakeGeenVies() + [
        'btwzoeken.be/*' => Http::response(btwzoekenValidResponse()),
    ]);

    $result = app(VatValidator::class)->lookup('BE0123456789');

    expect($result->valid)->toBeTrue()
        ->and($result->source)->toBe('btwzoeken')
        ->and($result->name)->toBe('Frituur Het Vosje')
        ->and($result->vatNumber)->toBe('BE0123456789')
        ->and($result->countryCode)->toBe('BE')
        ->and($result->address->street)->toBe('Vlasmarkt')
        ->and($result->address->zipCode)->toBe('9000')
        ->and($result->address->city)->toBe('Gent')
        ->and($result->address->countryCode)->toBe('BE');

    // controleerbtwnummer.eu staat erachter en hoort niet meer bevraagd te zijn.
    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'controleerbtwnummer.eu'));
});

it('houdt het busnummer bij het huisnummer', function () {
    Http::fake(fakeGeenVies() + [
        'btwzoeken.be/*' => Http::response(btwzoekenValidResponse()),
    ]);

    $result = app(VatValidator::class)->lookup('BE0123456789');

    // De KBO houdt nummer en bus apart; VatAddress draagt er één veld voor.
    expect($result->address->number)->toBe('12 bus B');
});

it('laat het nummer heel wanneer er geen bus is', function () {
    Http::fake(fakeGeenVies() + [
        'btwzoeken.be/*' => Http::response(btwzoekenValidResponse([
            'addresses' => [['street' => 'Vlasmarkt', 'number' => '12', 'box' => null, 'postal_code' => '9000', 'city' => 'Gent', 'country' => 'BE']],
        ])),
    ]);

    expect(app(VatValidator::class)->lookup('BE0123456789')->address->number)->toBe('12');
});

it('stuurt de sleutel mee als bearer token', function () {
    config()->set('vat-validator.btwzoeken.key', 'btwz_geheim');
    app()->forgetInstance(VatValidator::class);

    Http::fake(fakeGeenVies() + [
        'btwzoeken.be/*' => Http::response(btwzoekenValidResponse()),
    ]);

    app(VatValidator::class)->lookup('BE0123456789');

    Http::assertSent(fn ($request) => str_contains($request->url(), 'btwzoeken.be')
        && $request->hasHeader('Authorization', 'Bearer btwz_geheim'));
});

it('bevraagt btwzoeken.be zonder Authorization-kop wanneer er geen sleutel is', function () {
    Http::fake(fakeGeenVies() + [
        'btwzoeken.be/*' => Http::response(btwzoekenValidResponse()),
    ]);

    app(VatValidator::class)->lookup('BE0123456789');

    // Een lege bearer is geen sleutel maar wel een reden voor een 401.
    Http::assertSent(fn ($request) => str_contains($request->url(), 'btwzoeken.be')
        && ! $request->hasHeader('Authorization'));
});

it('slaat btwzoeken.be over wanneer de base_url leeg is', function () {
    config()->set('vat-validator.btwzoeken.base_url', '');
    app()->forgetInstance(VatValidator::class);

    Http::fake(fakeGeenVies() + [
        'controleerbtwnummer.eu/*' => Http::response(['valid' => true, 'name' => 'Fallback NV']),
    ]);

    expect(app(VatValidator::class)->lookup('BE0123456789')->source)->toBe('cbw');

    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'btwzoeken.be'));
});

it('gaat door naar de volgende bron bij een stopgezette onderneming', function () {
    Http::fake(fakeGeenVies() + [
        // De onderneming STAAT er, maar ze is niet meer actief. Dat is geen
        // geldig btw-nummer, en ook geen reden om te stoppen met zoeken.
        'btwzoeken.be/*' => Http::response(btwzoekenValidResponse(['active' => false])),
        'controleerbtwnummer.eu/*' => Http::response(['valid' => true, 'name' => 'Fallback NV']),
    ]);

    expect(app(VatValidator::class)->lookup('BE0123456789')->source)->toBe('cbw');
});

it('gaat door naar de volgende bron bij een 429', function () {
    Http::fake(fakeGeenVies() + [
        // Te snel bevraagd. Dat zegt niets over het nummer.
        'btwzoeken.be/*' => Http::response(['message' => 'Te veel verzoeken.'], 429),
        'controleerbtwnummer.eu/*' => Http::response(['valid' => true, 'name' => 'Fallback NV']),
    ]);

    expect(app(VatValidator::class)->lookup('BE0123456789')->source)->toBe('cbw');
});

it('volgt de volgorde die in sources staat', function () {
    config()->set('vat-validator.sources', ['btwzoeken', 'vies']);
    app()->forgetInstance(VatValidator::class);

    Http::fake([
        'btwzoeken.be/*' => Http::response(btwzoekenValidResponse()),
        'ec.europa.eu/*' => Http::response(viesValidResponse()),
    ]);

    expect(app(VatValidator::class)->lookup('BE0123456789')->source)->toBe('btwzoeken');

    // VIES staat erachter: wie een antwoord heeft, vraagt niet verder.
    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'ec.europa.eu'));
});

it('laat fallbacks_enabled false ook btwzoeken.be uitzetten', function () {
    config()->set('vat-validator.fallbacks_enabled', false);
    app()->forgetInstance(VatValidator::class);

    Http::fake(fakeGeenVies() + ['*' => Http::response(btwzoekenValidResponse())]);

    expect(app(VatValidator::class)->lookup('BE0123456789')->valid)->toBeFalse();
    Http::assertSentCount(1);
});

it('slaat een onbekende bronnaam over in plaats van te falen', function () {
    config()->set('vat-validator.sources', ['typefout', 'vies']);
    app()->forgetInstance(VatValidator::class);

    Http::fake(['ec.europa.eu/*' => Http::response(viesValidResponse())]);

    expect(app(VatValidator::class)->lookup('BE0123456789')->source)->toBe('vies');
});

it('vraagt het genormaliseerde nummer op', function () {
    Http::fake(fakeGeenVies() + [
        'btwzoeken.be/*' => Http::response(btwzoekenValidResponse()),
    ]);

    // Met punten en zonder landcode ingevoerd; de API hoort BE0123456789 te zien.
    app(VatValidator::class)->lookup('0123.456.789');

    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/companies/BE0123456789'));
});
