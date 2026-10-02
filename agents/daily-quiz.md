# Daily quiz

Goal: exactly one quiz per day, 10 multiple-choice questions, stored before visitors arrive.

## Steps

1. `GET /agent/context`. If `hasQuizToday` is true, stop and report "quiz already exists".
2. Write the quiz (rules below), avoiding everything in `recentQuizQuestions`.
3. `POST /quizzes` with the JSON body below. `date` defaults to today (Europe/Sarajevo).
4. On 422 read `message` (e.g. a question without 4 distinct options), fix and resend once. On 409 the day already has a quiz: stop (only send `"replace": true` when the task explicitly asks to replace it).
5. Report the title and `https://geovizija.com/quiz/{date}`.

## Body

```json
{
  "date": "2026-10-02",
  "title": "Od Sahare do Sjevernog pola",
  "intro": "Jedna rečenica koja najavljuje kviz.",
  "questions": [
    {
      "topic": "Geografija",
      "question": "Koji je glavni grad Australije?",
      "options": ["Sydney", "Canberra", "Melbourne", "Perth"],
      "correct": 1,
      "explanation": "Canberra je izgrađena kao kompromis između Sydneyja i Melbournea."
    }
  ]
}
```

`correct` is the 0-based index of the right option. The server shuffles the options, so their order does not matter.

## Content rules

- Exactly 10 questions; each with exactly 4 short, distinct options and one unambiguous correct answer.
- Topic mix: world geography (countries, capitals, rivers, mountains, deserts), Bosnia and Herzegovina and the region, nature and animals, climate and the Earth, travel and culture, history of exploration.
- Difficulty: 3 easy, 4 medium, 3 hard.
- Only stable, well-known facts. No current events, changing statistics or contested claims (e.g. whether the Nile or the Amazon is longest) — neither in the question nor in the explanation.
- `explanation`: one interesting sentence explaining the answer. `topic`: one or two words ("Geografija", "Životinje", "BiH").
- `title`: short and imaginative (≤ 50 characters), without the words "Geovizija" or "kviz". `intro`: one sentence.
- Everything in Bosnian (ijekavica, latinica), single-line strings.
