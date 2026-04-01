<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('document_chunks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained('documents')->cascadeOnDelete();
            $table->unsignedInteger('chunk_index');
            $table->longText('content');
            $table->text('excerpt')->nullable();

            if (Schema::getConnection()->getDriverName() === 'pgsql') {
                $table->vector('embedding', dimensions: 1536)->nullable();
            } else {
                $table->text('embedding')->nullable(); // JSON-encoded fallback for non-PostgreSQL
            }

            $table->unsignedInteger('char_count')->nullable();
            $table->unsignedInteger('token_count')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['document_id', 'chunk_index']);
            $table->index('document_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('document_chunks');
    }
};
