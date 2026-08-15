<?php

declare(strict_types=1);

use HansDeBoeck\VatValidator\VatLookupResult;
use HansDeBoeck\VatValidator\VatValidator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Bewust geen beforeEach: een tweede Http::fake() vervangt bestaande stubs
 * niet maar voegt eraan toe, waarbij de eerst geregistreerde stub wint. Een
 * test die een afwijkend antwoord nodig heeft zou dat dan niet krijgen.
 */
function fakeValidVies(): void
{
    Http::fake([
        'ec.europa.eu/*' => Http::response(viesValidResponse()),
    ]);
}

it('cachet een array en geen object', function () {
    fakeValidVies();

    app(VatValidator::class)->lookup('BE0123456789');

    $raw = Cache::get('vat:BE0123456789');

    expect($raw)->toBeArray()
        ->and($raw)->not->toBeInstanceOf(VatLookupResult::class);
});

it('haalt een tweede lookup uit de cache zonder nieuwe http-call', function () {
    fakeValidVies();

    $validator = app(VatValidator::class);

    $first = $validator->lookup('BE0123456789');
    $second = $validator->lookup('BE0123456789');

    Http::assertSentCount(1);

    expect($second->valid)->toBeTrue()
        ->and($second->vatNumber)->toBe($first->vatNumber)
        ->and($second->name)->toBe($first->name)
        ->and($second->source)->toBe($first->source)
        ->and($second->countryCode)->toBe($first->countryCode)
        ->and($second->toArray())->toBe($first->toArray());
});

it('behoudt het geneste adres volledig over de cache heen', function () {
    fakeValidVies();

    $validator = app(VatValidator::class);

    $first = $validator->lookup('BE0123456789');
    $second = $validator->lookup('BE0123456789');

    expect($second->address)->not->toBeNull()
        ->and($second->address->street)->toBe($first->address->street)
        ->and($second->address->number)->toBe($first->address->number)
        ->and($second->address->zipCode)->toBe($first->address->zipCode)
        ->and($second->address->city)->toBe($first->address->city)
        ->and($second->address->country)->toBe($first->address->country)
        ->and($second->address->countryCode)->toBe($first->address->countryCode);
});

it('behoudt countryCode, dat toArray() niet meeneemt', function () {
    fakeValidVies();

    $validator = app(VatValidator::class);

    $validator->lookup('BE0123456789');
    $second = $validator->lookup('BE0123456789');

    expect($second->countryCode)->toBe('BE')
        ->and(array_key_exists('country_code', $second->toArray()))->toBeFalse();
});

it('negeert een cache-entry met een onbekende versie', function () {
    fakeValidVies();

    Cache::put('vat:BE0123456789', ['v' => 99, 'valid' => true, 'name' => 'Oud'], 3600);

    $result = app(VatValidator::class)->lookup('BE0123456789');

    Http::assertSentCount(1);
    expect($result->name)->toBe('ACME BVBA');
});

it('cachet niets wanneer de cache uitgeschakeld is', function () {
    fakeValidVies();

    config()->set('vat-validator.cache_enabled', false);
    app()->forgetInstance(VatValidator::class);

    app(VatValidator::class)->lookup('BE0123456789');
    app(VatValidator::class)->lookup('BE0123456789');

    Http::assertSentCount(2);
    expect(Cache::get('vat:BE0123456789'))->toBeNull();
});

/**
 * De echte Laravel 13-regressie. De array-store serialiseert niet, dus die
 * merkt niets van `serializable_classes`. Een store die wel serialiseert
 * (hier file, net als redis, database en storage) roept
 * `unserialize($value, ['allowed_classes' => false])` aan; een gecachet
 * object komt er dan als __PHP_Incomplete_Class uit en de cache mist voor
 * altijd. Met een array-payload speelt dat niet.
 */
it('overleeft serializable_classes op een store die wel serialiseert', function () {
    fakeValidVies();

    config()->set('cache.default', 'file');
    config()->set('cache.stores.file', [
        'driver' => 'file',
        'path' => sys_get_temp_dir() . '/vat-validator-cache-test',
    ]);
    config()->set('cache.serializable_classes', false);

    Cache::forgetDriver('file');
    app()->forgetInstance(VatValidator::class);

    $validator = app(VatValidator::class);

    $first = $validator->lookup('BE0123456789');
    $second = $validator->lookup('BE0123456789');

    Http::assertSentCount(1);

    expect($second->valid)->toBeTrue()
        ->and($second->name)->toBe($first->name)
        ->and($second->countryCode)->toBe('BE')
        ->and($second->address->city)->toBe('GENT');

    Cache::forget('vat:BE0123456789');
});

it('cachet ongeldige resultaten niet', function () {
    Http::fake([
        'ec.europa.eu/*' => Http::response(['isValid' => false]),
        'controleerbtwnummer.eu/*' => Http::response(['valid' => false]),
        'www.btw-opzoeken.be/*' => Http::response([]),
    ]);

    $result = app(VatValidator::class)->lookup('BE0123456789');

    expect($result->valid)->toBeFalse()
        ->and(Cache::get('vat:BE0123456789'))->toBeNull();
});
