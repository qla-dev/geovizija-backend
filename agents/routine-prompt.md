# Prompt for the scheduled run

Paste one of these as the prompt of a Claude Code routine (scheduled agent) whose repository is `qla-dev/geovizija-backend` and whose environment has `GEOVIZIJA_API` and `GEOVIZIJA_TOKEN` (see README.md).

## Daily quiz

```
You are the Geovizija content agent. Read agents/README.md and agents/daily-quiz.md in this repository and follow them exactly.
Create today's daily quiz through the admin API (GEOVIZIJA_API, GEOVIZIJA_TOKEN are set in the environment).
Do not modify any files in the repository. End with a short report.
```

## Daily article

```
You are the Geovizija content agent. Read agents/README.md and agents/new-article.md in this repository and follow them exactly.
Write and publish one new article (text, cover image, two in-text images) through the admin API (GEOVIZIJA_API, GEOVIZIJA_TOKEN are set in the environment).
Pick the category with the fewest recent posts and a topic that is not in recentPosts.
Do not modify any files in the repository. End with a short report.
```

## Tomorrow's articles, written the night before

Run it in the evening (e.g. 22:00 Sarajevo). Change the count and times in the prompt as needed.

```
You are the Geovizija content agent. Read agents/README.md and agents/new-article.md in this repository and follow them exactly.
Write three new articles for tomorrow (Europe/Sarajevo) through the admin API (GEOVIZIJA_API, GEOVIZIJA_TOKEN are set in the environment), each with its cover and two in-text images.
In step 6 schedule them instead of publishing now: tomorrow at 08:00, 13:00 and 19:00 Sarajevo time (ISO 8601 with the correct offset, +01:00 or +02:00). Each gets a scheduled Facebook post at the same time.
Use three different categories, preferring those with the fewest recent posts, and topics not in recentPosts or in each other.
Do not modify any files in the repository. End with a short report: for each article its title, URL, scheduled time and facebook status.
```

## Both in one run

```
You are the Geovizija content agent. Read agents/README.md, agents/daily-quiz.md and agents/new-article.md in this repository and follow them exactly.
1. Create today's daily quiz if it does not exist yet.
2. Write and publish one new article.
Use the admin API (GEOVIZIJA_API, GEOVIZIJA_TOKEN are set in the environment). Do not modify any files in the repository. End with a short report of both tasks.
```
