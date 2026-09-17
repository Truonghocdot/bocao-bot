<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scrape_snapshots', function (Blueprint $table) {
            $table->id();
            $table->string('from_date', 10);
            $table->string('to_date', 10);
            $table->string('status', 32)->default('building');
            $table->unsignedInteger('source_total_records')->default(0);
            $table->unsignedInteger('source_total_pages')->default(0);
            $table->unsignedInteger('last_seen_total_records')->default(0);
            $table->unsignedInteger('last_seen_total_pages')->default(0);
            $table->unsignedInteger('scraped_pages')->default(0);
            $table->unsignedInteger('expected_files')->default(0);
            $table->unsignedInteger('downloaded_files')->default(0);
            $table->string('download_key')->nullable()->unique();
            $table->string('download_dir')->nullable();
            $table->timestamp('checked_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index(['from_date', 'to_date', 'status'], 'snapshots_range_status_index');
            $table->index(['status', 'last_used_at'], 'snapshots_cleanup_index');
        });

        Schema::create('scrape_snapshot_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('scrape_snapshot_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('page_number');
            $table->unsignedInteger('global_index');
            $table->string('company_name')->nullable();
            $table->string('filename');
            $table->string('relative_path');
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->timestamps();

            $table->unique(['scrape_snapshot_id', 'global_index'], 'snapshot_files_position_unique');
            $table->index(['scrape_snapshot_id', 'page_number'], 'snapshot_files_page_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scrape_snapshot_files');
        Schema::dropIfExists('scrape_snapshots');
    }
};
