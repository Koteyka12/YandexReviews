<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organization_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('yandex_review_id');
            $table->string('author')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('text')->nullable();
            $table->unsignedTinyInteger('rating')->nullable();
            $table->json('raw_payload')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'yandex_review_id']);
            $table->index(['organization_id', 'reviewed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_reviews');
    }
};
