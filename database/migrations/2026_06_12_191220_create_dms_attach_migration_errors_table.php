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
        Schema::create('dms_attach_migration_errors', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('attach_id')->index();

            $table->string('worker_id', 100)->nullable();

            $table->text('error_message');

            $table->longText('error_trace')->nullable();

            $table->timestamp('created_at')->useCurrent();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('dms_attach_migration_errors');
    }
};
