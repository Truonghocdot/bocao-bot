<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scrape_jobs', function (Blueprint $table) {
            $table->foreignId('scrape_schedule_id')
                ->nullable()
                ->after('chat_id')
                ->index();
            $table->string('download_key')->nullable()->after('max_records');
            $table->string('download_dir')->nullable()->after('download_key');
            $table->timestamp('delivered_at')->nullable()->after('zip_path');
        });

        Schema::table('scrape_schedules', function (Blueprint $table) {
            $table->string('last_download_key')->nullable()->after('max_records');
            $table->string('last_download_dir')->nullable()->after('last_download_key');
        });
    }

    public function down(): void
    {
        Schema::table('scrape_schedules', function (Blueprint $table) {
            $table->dropColumn(['last_download_key', 'last_download_dir']);
        });

        Schema::table('scrape_jobs', function (Blueprint $table) {
            $table->dropIndex(['scrape_schedule_id']);
            $table->dropColumn(['scrape_schedule_id', 'download_key', 'download_dir', 'delivered_at']);
        });
    }
};
