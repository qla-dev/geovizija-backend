<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Comment extends Model
{
    protected $fillable = ['post_id', 'parent_id', 'author', 'body', 'ip_hash'];

    protected $hidden = ['ip_hash'];

    protected function casts(): array
    {
        return [
            'likes' => 'integer',
        ];
    }

    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }

    public function replies(): HasMany
    {
        return $this->hasMany(Comment::class, 'parent_id')->oldest();
    }
}
