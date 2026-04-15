<?php

use App\Providers\AppServiceProvider;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

uses(TestCase::class);

test('forces HTTPS scheme in production', function () {
    $originalEnvironment = app()->environment();
    app()->instance('env', 'production');

    (new AppServiceProvider(app()))->boot();

    $urlGenerator = app('url');
    $forceSchemeProperty = (new ReflectionClass($urlGenerator))->getProperty('forceScheme');
    $forceSchemeProperty->setAccessible(true);

    $forcedScheme = $forceSchemeProperty->getValue($urlGenerator);

    expect($forcedScheme)->toBe('https://');

    URL::forceScheme(null);
    app()->instance('env', $originalEnvironment);
});
