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
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            Schema::ensureVectorExtensionExists();
        }

        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('file_path');
            $table->string('file_type');
            $table->longText('content');
            $table->text('excerpt')->nullable();
            if (Schema::getConnection()->getDriverName() === 'pgsql') {
                $table->vector('embedding', dimensions: 1536)->nullable();
            } else {
                $table->text('embedding')->nullable(); // JSON-encoded fallback for non-PostgreSQL
            }
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};
