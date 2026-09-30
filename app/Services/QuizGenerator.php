<?php

namespace App\Services;

use App\Models\Quiz;
use App\Models\QuizQuestion;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Writes the daily 10-question multiple-choice quiz through OpenRouter's text models.
 *
 * Recent questions are sent along so the model avoids repeating them. Answers are validated
 * (10 questions, 4 distinct options, one correct index) before anything is stored.
 */
class QuizGenerator
{
    public const QUESTIONS = 10;

    public const TIMEZONE = 'Europe/Sarajevo';

    public static function today(): string
    {
        return Carbon::now(self::TIMEZONE)->toDateString();
    }

    /**
     * Returns the quiz for $date. With QUIZ_AUTO_GENERATE on, a missing quiz is generated through
     * OpenRouter; with it off (quizzes are written by the Claude agent, see agents/), the latest
     * earlier quiz is returned instead.
     */
    public function forDate(string $date): Quiz
    {
        if ($quiz = Quiz::whereDate('date', $date)->first()) {
            return $quiz;
        }

        if (! config('services.quiz.auto_generate')) {
            return Quiz::whereDate('date', '<=', $date)->orderByDesc('date')->firstOrFail();
        }

        // Only one request generates; concurrent visitors wait for it and then read the result.
        return Cache::lock("quiz-generate:{$date}", 180)->block(170, function () use ($date) {
            return Quiz::whereDate('date', $date)->first() ?? $this->generate($date);
        });
    }

    public function generate(string $date, bool $replace = false): Quiz
    {
        $apiKey = (string) config('services.openrouter.api_key');
        if ($apiKey === '') {
            throw new RuntimeException('OPENROUTER_API_KEY is not configured.');
        }

        $model = (string) config('services.openrouter.model');
        $errors = [];

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            try {
                $data = $this->request($date, $model, $apiKey);
            } catch (RuntimeException $exception) {
                $errors[] = $exception->getMessage();
                Log::warning('Quiz generation failed.', ['date' => $date, 'attempt' => $attempt, 'error' => $exception->getMessage()]);

                continue;
            }

            return $this->store($date, $data, $replace);
        }

        throw new RuntimeException(implode(' | ', $errors));
    }

    /**
     * Validates and saves a quiz written elsewhere (the Claude agent via POST /api/quizzes).
     *
     * @throws RuntimeException when the questions do not pass validation
     */
    public function storeExternal(string $date, array $data, bool $replace = false): Quiz
    {
        return $this->store($date, $this->validate($data), $replace);
    }

    private function store(string $date, array $data, bool $replace): Quiz
    {
        return DB::transaction(function () use ($date, $data, $replace) {
            if ($replace) {
                Quiz::whereDate('date', $date)->delete();
            }

            $quiz = Quiz::create(['date' => $date, 'title' => $data['title'], 'intro' => $data['intro']]);

            foreach ($data['questions'] as $index => $question) {
                QuizQuestion::create([
                    'quiz_id' => $quiz->id,
                    'position' => $index + 1,
                    'topic' => $question['topic'],
                    'question' => $question['question'],
                    'options' => $question['options'],
                    'correct_index' => $question['correct'],
                    'explanation' => $question['explanation'],
                ]);
            }

            return $quiz;
        });
    }

    /** @return array{title: string, intro: string, questions: list<array{topic: string, question: string, options: list<string>, correct: int, explanation: string}>} */
    private function request(string $date, string $model, string $apiKey): array
    {
        $recent = QuizQuestion::query()->latest('id')->limit(80)->pluck('question')->implode("\n- ");

        try {
            $response = Http::withToken($apiKey)
                ->acceptJson()
                ->timeout(150)
                ->withHeaders(['HTTP-Referer' => config('app.url'), 'X-Title' => 'Geovizija daily quiz'])
                ->post((string) config('services.openrouter.url'), [
                    'model' => $model,
                    'response_format' => ['type' => 'json_object'],
                    'temperature' => 0.9,
                    'messages' => [
                        ['role' => 'system', 'content' => $this->instructions()],
                        ['role' => 'user', 'content' => "Datum kviza: {$date}.\n\nNe ponavljaj ova nedavna pitanja:\n- ".($recent ?: '(nema)')],
                    ],
                ]);
        } catch (ConnectionException $exception) {
            throw new RuntimeException('The quiz generator is not available right now: '.$exception->getMessage());
        }

        if (! $response->successful()) {
            throw new RuntimeException(data_get($response->json(), 'error.message') ?: "The quiz generator returned HTTP {$response->status()}.");
        }

        // Quiz strings are single-line, so raw newlines (which break Gemini's JSON) can be flattened.
        $raw = trim((string) data_get($response->json(), 'choices.0.message.content'));
        $raw = (string) preg_replace('/^```\w*\s*|\s*```$/', '', $raw);
        $data = json_decode(str_replace(["\r", "\n"], ' ', $raw), true);

        if (! is_array($data)) {
            throw new RuntimeException('The quiz generator returned invalid JSON ('.json_last_error_msg().').');
        }

        return $this->validate($data);
    }

    private function validate(array $data): array
    {
        $questions = [];

        foreach ((array) ($data['questions'] ?? []) as $item) {
            $options = array_values(array_map(fn ($o) => trim((string) $o), (array) ($item['options'] ?? [])));
            $correct = $item['correct'] ?? null;
            $question = trim((string) ($item['question'] ?? ''));

            if ($question === '' || count($options) !== 4 || count(array_unique(array_map('mb_strtolower', $options))) !== 4
                || in_array('', $options, true) || ! is_int($correct) || $correct < 0 || $correct > 3) {
                continue;
            }

            $questions[] = [
                'topic' => mb_substr(trim((string) ($item['topic'] ?? '')), 0, 100),
                'question' => $question,
                'options' => $options,
                'correct' => $correct,
                'explanation' => trim((string) ($item['explanation'] ?? '')),
            ];
        }

        if (count($questions) < self::QUESTIONS) {
            throw new RuntimeException('The quiz generator returned only '.count($questions).' valid questions.');
        }

        // Models favour one answer slot; shuffle options so the correct answer lands anywhere.
        $questions = array_map(function (array $q) {
            $answer = $q['options'][$q['correct']];
            shuffle($q['options']);
            $q['correct'] = array_search($answer, $q['options'], true);

            return $q;
        }, array_slice($questions, 0, self::QUESTIONS));

        return [
            'title' => trim((string) ($data['title'] ?? '')) ?: 'Dnevni kviz',
            'intro' => trim((string) ($data['intro'] ?? '')),
            'questions' => $questions,
        ];
    }

    private function instructions(): string
    {
        return <<<'TXT'
Ti si urednik dnevnog kviza magazina Geovizija (priroda, geografija, putovanja, kultura, društvo, nauka) u stilu National Geographica.
Napiši kviz od tačno 10 pitanja na bosanskom jeziku (ijekavica, latinica).

Pravila:
- Mješavina tema: geografija svijeta (države, glavni gradovi, rijeke, planine, pustinje), Bosna i Hercegovina i region, priroda i životinje, klima i Zemlja, putovanja i kultura, istorija otkrića.
- Mješavina težine: 3 lagana, 4 srednja, 3 teška pitanja.
- Svako pitanje ima tačno 4 kratka odgovora, samo jedan je tačan, ostali su uvjerljivi ali nedvosmisleno netačni.
- Samo općepoznate, provjerljive i stabilne činjenice. Bez pitanja o aktuelnim događajima, statistikama koje se mijenjaju ili spornim tvrdnjama (npr. da li je Nil ili Amazon najduža rijeka svijeta) — ni u pitanju ni u objašnjenju.
- "explanation": jedna zanimljiva rečenica koja objašnjava tačan odgovor; ne smije sadržavati netačne ni sporne tvrdnje.
- "topic": jedna ili dvije riječi (npr. "Geografija", "Životinje", "BiH").
- "title": kratak, maštovit naslov (do 50 znakova) koji dočarava temu ovog kviza, npr. "Od Sahare do Sjevernog pola"; bez riječi "Geovizija" i "kviz". "intro": jedna rečenica najave.
- Sav tekst u jednom redu, bez prelazaka u novi red.

Vrati isključivo JSON:
{"title": "...", "intro": "...", "questions": [{"topic": "...", "question": "...", "options": ["...", "...", "...", "..."], "correct": 0, "explanation": "..."}]}
"correct" je indeks tačnog odgovora (0-3).
TXT;
    }
}
