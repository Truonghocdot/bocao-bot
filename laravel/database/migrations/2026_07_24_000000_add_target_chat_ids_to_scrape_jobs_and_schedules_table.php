<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scrape_jobs', function (Blueprint $table) {
            $table->json('target_chat_ids')->nullable()->after('target_chat_id');
        });

        Schema::table('scrape_schedules', function (Blueprint $table) {
            $table->json('target_chat_ids')->nullable()->after('target_chat_id');
        });
    }

    public function down(): void
    {
        Schema::table('scrape_jobs', function (Blueprint $table) {
            $table->dropColumn('target_chat_ids');
        });

        Schema::table('scrape_schedules', function (Blueprint $table) {
            $table->dropColumn('target_chat_ids');
        });
    }
};
