<?php

use App\Services\PgVectorPreflightService;
use Tests\TestCase;

uses(TestCase::class);

test('preflight check is skipped when database driver is not PostgreSQL', function () {
    $service = new class extends PgVectorPreflightService
    {
        protected function currentDriver(): string
        {
            return 'sqlite';
        }

        protected function vectorExtensionExists(): bool
        {
            throw new RuntimeException('vector extension lookup should be skipped for non-pgsql drivers');
        }
    };

    $service->ensureVectorExtensionReady();

    expect(true)->toBeTrue();
});
