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
        Schema::table('documents', function (Blueprint $table) {
            $table->string('status')->default('completed')->after('embedding');
            $table->text('error_message')->nullable()->after('status');
            $table->timestamp('processing_started_at')->nullable()->after('error_message');
            $table->timestamp('completed_at')->nullable()->after('processing_started_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropColumn([
                'status',
                'error_message',
                'processing_started_at',
                'completed_at',
            ]);
        });
    }
};
