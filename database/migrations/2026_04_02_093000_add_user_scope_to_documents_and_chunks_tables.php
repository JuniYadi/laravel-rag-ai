<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->after('id')->constrained()->nullOnDelete();
            $table->index('user_id');
        });

        Schema::table('document_chunks', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->after('document_id')->constrained()->nullOnDelete();
            $table->index('user_id');
        });

        DB::table('document_chunks')
            ->whereNull('user_id')
            ->update([
                'user_id' => DB::raw('(select user_id from documents where id = document_chunks.document_id)'),
            ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('document_chunks', function (Blueprint $table) {
            $table->dropConstrainedForeignId('user_id');
        });

        Schema::table('documents', function (Blueprint $table) {
            $table->dropConstrainedForeignId('user_id');
        });
    }
};
