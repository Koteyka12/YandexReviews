<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organizations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('yandex_id');
            $table->text('source_url');
            $table->text('canonical_url')->nullable();
            $table->string('title')->nullable();
            $table->text('address')->nullable();
            $table->decimal('rating_value', 3, 2)->nullable();
            $table->unsignedInteger('rating_count')->default(0);
            $table->unsignedInteger('review_count')->default(0);
            $table->unsignedInteger('scraped_reviews_count')->default(0);
            $table->string('scrape_status')->default('pending');
            $table->text('scrape_error')->nullable();
            $table->timestamp('last_scraped_at')->nullable();
            $table->json('raw_meta')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'yandex_id']);
            $table->index(['user_id', 'updated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organizations');
    }
};
