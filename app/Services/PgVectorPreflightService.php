<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use RuntimeException;

class PgVectorPreflightService
{
    public function ensureVectorExtensionReady(): void
    {
        if ($this->currentDriver() !== 'pgsql') {
            return;
        }

        if (! $this->vectorExtensionExists()) {
            throw new RuntimeException(
                'PostgreSQL pgvector extension is not installed. Run: CREATE EXTENSION IF NOT EXISTS vector; '
                .'then retry the RAG query.'
            );
        }
    }

    protected function currentDriver(): string
    {
        return DB::connection()->getDriverName();
    }

    protected function vectorExtensionExists(): bool
    {
        return DB::table('pg_extension')
            ->where('extname', 'vector')
            ->exists();
    }
}
