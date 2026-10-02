<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\CommentResource;
use App\Models\Comment;
use App\Models\Post;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Anonymous article comments: name + text, one level of replies, likes.
 * Automatic moderation: honeypot field, rate limits (routes), link limit and
 * duplicate check per IP. Admins delete with the admin token.
 */
class CommentController extends Controller
{
    private const MAX_LINKS = 1;

    /** Top-level comments, newest first, each with its replies oldest first. */
    public function index(Post $post)
    {
        abort_unless($post->isPublished(), 404);

        $comments = $post->comments()
            ->whereNull('parent_id')
            ->with('replies')
            ->latest()
            ->latest('id')
            ->limit(200)
            ->get();

        return CommentResource::collection($comments);
    }

    public function store(Request $request, Post $post)
    {
        abort_unless($post->isPublished(), 404);

        $data = $request->validate([
            'author' => ['required', 'string', 'min:2', 'max:60'],
            'body' => ['required', 'string', 'min:2', 'max:2000'],
            'parentId' => ['nullable', 'integer'],
            // Honeypot: hidden in the form, only bots fill it.
            'website' => ['nullable', 'max:0'],
        ], [
            'author.required' => 'Upišite ime.',
            'author.min' => 'Ime je prekratko.',
            'author.max' => 'Ime može imati najviše 60 znakova.',
            'body.required' => 'Komentar je prazan.',
            'body.min' => 'Komentar je prekratak.',
            'body.max' => 'Komentar može imati najviše 2000 znakova.',
            'website.max' => 'Komentar nije prihvaćen.',
        ]);

        $author = trim(strip_tags($data['author']));
        $body = trim(strip_tags($data['body']));
        $ipHash = hash('sha256', $request->ip().'|'.config('app.key'));

        $parentId = null;
        if (! empty($data['parentId'])) {
            $parent = Comment::where('post_id', $post->id)->whereNull('parent_id')->find($data['parentId']);
            if (! $parent) {
                throw ValidationException::withMessages(['parentId' => 'Komentar na koji odgovarate ne postoji.']);
            }
            $parentId = $parent->id;
        }

        if (preg_match_all('~(https?://|www\.)~i', $body) > self::MAX_LINKS) {
            throw ValidationException::withMessages(['body' => 'Komentar smije imati najviše jedan link.']);
        }

        $duplicate = Comment::where('ip_hash', $ipHash)
            ->where('body', $body)
            ->where('created_at', '>=', now()->subMinutes(10))
            ->exists();
        if ($duplicate) {
            throw ValidationException::withMessages(['body' => 'Isti komentar je već objavljen.']);
        }

        $comment = Comment::create([
            'post_id' => $post->id,
            'parent_id' => $parentId,
            'author' => $author,
            'body' => $body,
            'ip_hash' => $ipHash,
        ]);

        return (new CommentResource($comment->setRelation('replies', collect())))->response()->setStatusCode(201);
    }

    public function like(Comment $comment)
    {
        $comment->increment('likes');

        return response()->json(['data' => ['id' => $comment->id, 'likes' => $comment->likes]]);
    }

    public function unlike(Comment $comment)
    {
        if ($comment->likes > 0) {
            $comment->decrement('likes');
        }

        return response()->json(['data' => ['id' => $comment->id, 'likes' => $comment->likes]]);
    }

    public function destroy(Comment $comment)
    {
        $comment->delete();

        return response()->noContent();
    }
}
