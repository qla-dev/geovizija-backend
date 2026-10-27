<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            // Photo credits shown as text under the cover and in-text images, keyed by the stored image
            // path, e.g. {"media/posts/x.jpg": "Foto: Pudelek / Wikimedia Commons, CC BY-SA 4.0"}.
            $table->json('image_credits')->nullable()->after('image_url');
        });
    }

    public function down(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->dropColumn('image_credits');
        });
    }
};
