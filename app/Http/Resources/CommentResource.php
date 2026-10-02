<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CommentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'parentId' => $this->parent_id,
            'author' => $this->author,
            'body' => $this->body,
            'likes' => $this->likes,
            'createdAt' => $this->created_at?->toIso8601String(),
            'replies' => CommentResource::collection($this->whenLoaded('replies')),
        ];
    }
}
