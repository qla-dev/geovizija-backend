<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            // Set while a scheduled article is held back in the admin panel: it does not go live (nor to
            // Instagram) even after published_at, until resumed. See Post::scopePublished.
            $table->timestamp('paused_at')->nullable()->after('published_at');
        });
    }

    public function down(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->dropColumn('paused_at');
        });
    }
};
