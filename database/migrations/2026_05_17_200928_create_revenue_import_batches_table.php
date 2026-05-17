<?php

use Illuminate\Database\Migrations\Migration;
use App\Enums\BatchStatus;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('revenue_import_batches', function (Blueprint $table) {
            $table->id();
            // Idempotency primitive — SHA-256 of the canonical payload.
            $table->char('payload_hash', 64)->unique();
            // Lifecycle. Stored as string; cast to BatchStatus enum on the model.
            $table->enum('status', BatchStatus::cases())->default(BatchStatus::PENDING)->index();
            // Counters that mirror the API response summary.
            $table->unsignedInteger('record_count')->default(0);
            $table->unsignedInteger('imported_count')->default(0);
            $table->unsignedInteger('updated_count')->default(0);
            $table->unsignedInteger('skipped_count')->default(0);
            $table->unsignedInteger('new_machines_count')->default(0);

            // Validation errors when status = rejected.
            $table->json('error_payload')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('revenue_import_batches');
    }
};
