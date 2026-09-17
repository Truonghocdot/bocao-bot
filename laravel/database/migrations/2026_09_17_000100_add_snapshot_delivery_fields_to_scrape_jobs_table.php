<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scrape_jobs', function (Blueprint $table) {
            $table->foreignId('scrape_snapshot_id')
                ->nullable()
                ->after('scrape_schedule_id')
                ->constrained()
                ->nullOnDelete();
            $table->unsignedInteger('delivery_cursor')->default(0)->after('downloaded_count');
            $table->unsignedInteger('sent_count')->default(0)->after('delivery_cursor');
            $table->unsignedInteger('failed_count')->default(0)->after('sent_count');
            $table->text('delivery_error_message')->nullable()->after('error_message');
        });
    }

    public function down(): void
    {
        Schema::table('scrape_jobs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('scrape_snapshot_id');
            $table->dropColumn([
                'delivery_cursor',
                'sent_count',
                'failed_count',
                'delivery_error_message',
            ]);
        });
    }
};
