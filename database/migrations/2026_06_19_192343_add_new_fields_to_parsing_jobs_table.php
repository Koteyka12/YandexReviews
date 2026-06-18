<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('parsing_jobs', function (Blueprint $table) {
            $table->boolean('has_new_reviews')->default(false)->after('scraped_reviews_count');
            $table->integer('previous_reviews_count')->default(0)->after('has_new_reviews');
            $table->integer('new_reviews_count')->default(0)->after('previous_reviews_count');
        });
    }

    public function down(): void
    {
        Schema::table('parsing_jobs', function (Blueprint $table) {
            $table->dropColumn(['has_new_reviews', 'previous_reviews_count', 'new_reviews_count']);
        });
    }
};