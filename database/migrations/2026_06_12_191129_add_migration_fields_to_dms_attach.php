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
        Schema::table('dms_attach', function (Blueprint $table) {
            $table->boolean('is_uploaded')->default(false)->index();

            $table->string('file_path', 1000)->nullable();

            $table->timestamp('processed_at')->nullable();

            $table->string('migration_status', 30)
                ->default('PENDING')
                ->index();

            $table->unsignedInteger('migration_attempts')
                ->default(0);

            $table->timestamp('migration_locked_at')->nullable()->index();

            $table->string('migration_worker_id', 100)->nullable();

            $table->text('migration_last_error')->nullable();

            $table->unsignedBigInteger('migration_file_size')->nullable();

            $table->string('migration_sha256', 64)->nullable();

            $table->timestamp('migration_queued_at')->nullable();

            // Composite index for fast filtering
            $table->index([
                'is_uploaded',
                'migration_status',
                'migration_locked_at',
            ], 'dms_attach_migration_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('dms_attach', function (Blueprint $table) {
            $table->dropIndex('dms_attach_migration_idx');

            $table->dropColumn([
                'is_uploaded',
                'file_path',
                'processed_at',
                'migration_status',
                'migration_attempts',
                'migration_locked_at',
                'migration_worker_id',
                'migration_last_error',
                'migration_file_size',
                'migration_sha256',
                'migration_queued_at',
            ]);
        });
    }
};
