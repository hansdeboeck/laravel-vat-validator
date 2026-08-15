<?php

declare(strict_types=1);

namespace HansDeBoeck\VatValidator\Tests;

use HansDeBoeck\VatValidator\VatValidatorServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
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
