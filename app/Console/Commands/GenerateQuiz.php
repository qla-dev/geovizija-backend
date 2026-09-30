<?php

namespace App\Console\Commands;

use App\Models\Quiz;
use App\Services\QuizGenerator;
use Illuminate\Console\Command;
use Throwable;

class GenerateQuiz extends Command
{
    protected $signature = 'quizzes:generate
        {--date= : Y-m-d (default: today in Europe/Sarajevo)}
        {--force : Replace an existing quiz for that date}';

    protected $description = 'Generate the daily quiz through OpenRouter';

    public function handle(QuizGenerator $generator): int
    {
        $date = $this->option('date') ?: QuizGenerator::today();

        if (! $this->option('force') && Quiz::whereDate('date', $date)->exists()) {
            $this->info("A quiz for {$date} already exists.");

            return self::SUCCESS;
        }

        try {
            $quiz = $generator->generate($date, replace: (bool) $this->option('force'));
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info("{$date}: {$quiz->title} ({$quiz->questions()->count()} questions)");

        return self::SUCCESS;
    }
}
