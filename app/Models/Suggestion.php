<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A topic idea from the admin panel for the daily content agent. */
class Suggestion extends Model
{
    protected $fillable = ['text', 'source', 'for_date', 'used_at'];

    protected function casts(): array
    {
        return [
            'for_date' => 'date',
            'used_at' => 'datetime',
        ];
    }

    public function toApi(): array
    {
        return [
            'id' => $this->id,
            'text' => $this->text,
            'source' => $this->source,
            'forDate' => $this->for_date?->toDateString(),
            'usedAt' => $this->used_at?->toIso8601String(),
            'createdAt' => $this->created_at?->toIso8601String(),
        ];
    }
}
