<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Quiz;
use App\Services\QuizGenerator;
use Illuminate\Http\Request;
use RuntimeException;
use Throwable;

class QuizController extends Controller
{
    /** Past and current quizzes, newest first (no questions). */
    public function index()
    {
        $quizzes = Quiz::query()
            ->whereDate('date', '<=', QuizGenerator::today())
            ->withCount('questions')
            ->orderByDesc('date')
            ->limit(60)
            ->get()
            ->map(fn (Quiz $quiz) => $this->summary($quiz));

        return response()->json(['data' => $quizzes, 'today' => QuizGenerator::today()]);
    }

    /** Today's quiz, generated on first request of the day. */
    public function today(QuizGenerator $generator)
    {
        try {
            $quiz = $generator->forDate(QuizGenerator::today());
        } catch (Throwable $exception) {
            report($exception);

            return response()->json(['message' => 'Današnji kviz se trenutno ne može pripremiti. Pokušajte ponovo za minut.'], 503);
        }

        return response()->json(['data' => $this->full($quiz)]);
    }

    public function show(string $date)
    {
        abort_unless(preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) && $date <= QuizGenerator::today(), 404);

        $quiz = Quiz::whereDate('date', $date)->firstOrFail();

        return response()->json(['data' => $this->full($quiz)]);
    }

    /**
     * Admin: store a quiz written outside the app (the Claude agent, see agents/daily-quiz.md).
     * Body: {date?, title, intro, questions: [{topic, question, options[4], correct 0-3, explanation}], replace?}
     */
    public function store(Request $request, QuizGenerator $generator)
    {
        $input = $request->validate([
            'date' => ['nullable', 'date_format:Y-m-d'],
            'title' => ['required', 'string', 'max:255'],
            'intro' => ['nullable', 'string', 'max:500'],
            'questions' => ['required', 'array', 'min:'.QuizGenerator::QUESTIONS],
            'replace' => ['sometimes', 'boolean'],
        ]);
        $date = $input['date'] ?? QuizGenerator::today();
        $replace = (bool) ($input['replace'] ?? false);

        if (! $replace && Quiz::whereDate('date', $date)->exists()) {
            return response()->json(['message' => "A quiz for {$date} already exists. Send \"replace\": true to overwrite it."], 409);
        }

        try {
            $quiz = $generator->storeExternal($date, $request->only('title', 'intro', 'questions'), $replace);
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json(['data' => $this->full($quiz)], 201);
    }

    /** Admin: (re)generate the quiz for a date (default today) through OpenRouter. */
    public function generate(Request $request, QuizGenerator $generator)
    {
        $date = $request->validate(['date' => ['nullable', 'date_format:Y-m-d']])['date'] ?? QuizGenerator::today();

        try {
            $quiz = $generator->generate($date, replace: true);
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 502);
        }

        return response()->json(['data' => $this->full($quiz)], 201);
    }

    private function summary(Quiz $quiz): array
    {
        return [
            'date' => $quiz->date->toDateString(),
            'title' => $quiz->title,
            'intro' => $quiz->intro,
            'questionsCount' => $quiz->questions_count ?? $quiz->questions()->count(),
        ];
    }

    private function full(Quiz $quiz): array
    {
        $quiz->loadMissing('questions');

        return [
            ...$this->summary($quiz),
            'questions' => $quiz->questions->map(fn ($q) => [
                'id' => $q->id,
                'topic' => $q->topic,
                'question' => $q->question,
                'options' => $q->options,
                'correctIndex' => $q->correct_index,
                'explanation' => $q->explanation,
            ])->values(),
        ];
    }
}
