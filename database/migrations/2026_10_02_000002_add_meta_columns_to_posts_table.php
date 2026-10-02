<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            // Facebook Page post created for this article (see App\Services\MetaPublisher).
            $table->string('meta_post_id', 100)->nullable();
            $table->timestamp('meta_shared_at')->nullable();
            $table->string('meta_error', 500)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->dropColumn(['meta_post_id', 'meta_shared_at', 'meta_error']);
        });
    }
};
