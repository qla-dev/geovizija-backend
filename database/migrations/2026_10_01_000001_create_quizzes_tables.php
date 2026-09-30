<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quizzes', function (Blueprint $table) {
            $table->id();
            $table->date('date')->unique();
            $table->string('title');
            $table->string('intro', 500)->nullable();
            $table->timestamps();
        });

        Schema::create('quiz_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quiz_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('position');
            $table->string('topic', 100)->nullable();
            $table->text('question');
            $table->json('options');
            $table->unsignedTinyInteger('correct_index');
            $table->text('explanation')->nullable();
            $table->timestamps();

            $table->unique(['quiz_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quiz_questions');
        Schema::dropIfExists('quizzes');
    }
};
