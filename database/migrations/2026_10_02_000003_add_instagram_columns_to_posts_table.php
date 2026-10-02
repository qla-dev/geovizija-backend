<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            // Instagram post for this article (see App\Services\InstagramPublisher): null = never queued
            // (older articles), pending = posted by instagram:publish-due once published_at has passed.
            $table->string('ig_status', 20)->nullable()->index();
            $table->string('ig_media_id', 100)->nullable();
            $table->timestamp('ig_shared_at')->nullable();
            $table->string('ig_error', 500)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->dropColumn(['ig_status', 'ig_media_id', 'ig_shared_at', 'ig_error']);
        });
    }
};
