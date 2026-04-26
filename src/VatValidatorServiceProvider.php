<?php

declare(strict_types=1);

namespace HansDeBoeck\VatValidator;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\ServiceProvider;

class VatValidatorServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../config/vat-validator.php',
            'vat-validator',
        );

        $this->app->singleton(VatValidator::class, function ($app) {
            return new VatValidator(
                $app->make(CacheRepository::class),
                $app['config']->get('vat-validator', []),
            );
        });
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/vat-validator.php' => config_path('vat-validator.php'),
            ], 'vat-validator-config');
        }

        // Custom validation rule: 'vat' / 'vat:strict'.
        Validator::extend('vat', function (string $attribute, mixed $value): bool {
            if (! is_string($value) || $value === '') {
                return false;
            }
            $result = app(VatValidator::class)->lookup($value);
            return $result->valid;
        }, 'Het BTW-nummer is niet geldig of niet gevonden.');
    }
}
