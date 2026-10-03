<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per page shown on the site (sent by the frontend on every route change, see TrackController).
        Schema::create('page_views', function (Blueprint $table) {
            $table->id();
            $table->string('path', 191)->index();
            $table->foreignId('post_id')->nullable()->index();
            // Hash of the browser's random id: counts unique visitors.
            $table->char('visitor', 16)->index();
            // Kept 30 days, then cleared (routes/console.php); stated on the privacy page.
            $table->string('ip', 45)->nullable();
            $table->string('referrer', 191)->nullable();
            $table->string('device', 10)->nullable();
            $table->timestamp('created_at')->useCurrent()->index();
        });

        // Topic ideas from the admin panel (typed or dictated); the daily agent reads the open ones.
        Schema::create('suggestions', function (Blueprint $table) {
            $table->id();
            $table->text('text');
            $table->string('source', 10)->default('typed');
            $table->date('for_date')->nullable();
            $table->timestamp('used_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('suggestions');
        Schema::dropIfExists('page_views');
    }
};
