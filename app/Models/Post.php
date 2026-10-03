<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Post extends Model
{
    protected $fillable = [
        'category_id', 'slug', 'title', 'excerpt', 'content',
        'image_url', 'author', 'read_time', 'featured', 'published_at',
    ];

    protected function casts(): array
    {
        return [
            'featured' => 'boolean',
            'read_time' => 'integer',
            'published_at' => 'datetime',
            'paused_at' => 'datetime',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class);
    }

    public function views(): HasMany
    {
        return $this->hasMany(PageView::class);
    }

    /** Live: its publication time has passed and it is not paused in the admin panel. */
    public function scopePublished(Builder $query): Builder
    {
        return $query->whereNotNull('published_at')->where('published_at', '<=', now())->whereNull('paused_at');
    }

    public function isPublished(): bool
    {
        return $this->published_at !== null && ! $this->published_at->isFuture() && $this->paused_at === null;
    }

    /** Waiting to go live: a future publication time, or held back by a pause. */
    public function isScheduled(): bool
    {
        return $this->published_at !== null && ! $this->isPublished();
    }

    /** Resolves a post by numeric id or by slug. */
    public function resolveRouteBinding($value, $field = null)
    {
        return $this->where(ctype_digit((string) $value) ? 'id' : 'slug', $value)->firstOrFail();
    }
}
