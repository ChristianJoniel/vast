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
        Schema::create('revenue_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('machine_id')->constrained('machines')->cascadeOnDelete();
            $table->foreignId('source_batch_id')->nullable()->constrained('revenue_import_batches')->nullOnDelete();
            $table->date('report_date');
            $table->decimal('cash_in', 12, 2);
            $table->decimal('voucher_in', 12, 2);
            $table->decimal('voucher_out', 12, 2);
            $table->decimal('net_revenue', 12, 2);
            $table->timestamps();

            // Idempotency primitive — one record per machine per report date.
            $table->unique(['machine_id', 'report_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('revenue_records');
    }
};
