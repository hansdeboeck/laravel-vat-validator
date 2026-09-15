<?php

declare(strict_types=1);

namespace HansDeBoeck\VatValidator\Tests;

use HansDeBoeck\VatValidator\VatValidatorServiceProvider;
use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();

        /*
        | Geen enkel verzoek naar buiten, ook niet per ongeluk.
        |
        | Http::fake() met een lijst patronen laat wat NIET matcht gewoon
        | doorgaan naar het echte internet. Dat is precies wat er gebeurde toen
        | btwzoeken.be als bron tussen VIES en controleerbtwnummer.eu kwam: elke
        | test die VIES liet falen, belde in stilte een echte server. Met deze
        | regel wordt zo'n gat een uitzondering met de URL erin, en geen test
        | die traag wordt en soms faalt.
        */
        Http::preventStrayRequests();
    }

    protected function getPackageProviders($app): array
    {
        return [
            VatValidatorServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('cache.default', 'array');

        // Laravel 13 zet dit standaard op false in de app-skeleton. Door het
        // hier ook op false te zetten testen we onder de scherpste stand: er
        // mag geen enkel object meer via de cache heen en weer gaan.
        $app['config']->set('cache.serializable_classes', false);
    }
}
