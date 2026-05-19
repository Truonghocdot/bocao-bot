<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scrape_jobs', function (Blueprint $table) {
            $table->string('from_date')->nullable()->after('chat_id');
            $table->string('to_date')->nullable()->after('from_date');
            $table->integer('max_records')->nullable()->after('to_date'); // null = lấy tất cả
        });

        Schema::table('scrape_schedules', function (Blueprint $table) {
            $table->integer('days_back')->default(1)->after('cron_expression');  // số ngày đổ lại
            $table->integer('max_records')->nullable()->after('days_back');      // null = lấy tất cả
        });
    }

    public function down(): void
    {
        Schema::table('scrape_jobs', function (Blueprint $table) {
            $table->dropColumn(['from_date', 'to_date', 'max_records']);
        });

        Schema::table('scrape_schedules', function (Blueprint $table) {
            $table->dropColumn(['days_back', 'max_records']);
        });
    }
};
