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
        Schema::create('scrape_schedules', function (Blueprint $table) {
            $table->id();
            $table->string('chat_id');
            $table->boolean('is_active')->default(true);
            $table->string('cron_expression')->default('0 8 * * *');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('scrape_schedules');
    }
};
