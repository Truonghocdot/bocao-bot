<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scrape_schedules', function (Blueprint $table) {
            $table->string('from_date')->nullable()->after('target_chat_id');
            $table->string('to_date')->nullable()->after('from_date');
        });
    }

    public function down(): void
    {
        Schema::table('scrape_schedules', function (Blueprint $table) {
            $table->dropColumn(['from_date', 'to_date']);
        });
    }
};
