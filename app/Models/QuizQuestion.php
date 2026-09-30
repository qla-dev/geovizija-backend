<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuizQuestion extends Model
{
    protected $fillable = ['quiz_id', 'position', 'topic', 'question', 'options', 'correct_index', 'explanation'];

    protected function casts(): array
    {
        return ['options' => 'array', 'correct_index' => 'integer', 'position' => 'integer'];
    }

    public function quiz(): BelongsTo
    {
        return $this->belongsTo(Quiz::class);
    }
}
