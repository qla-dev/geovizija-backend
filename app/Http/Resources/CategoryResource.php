<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Field names match the frontend `Category` type (id = slug). */
class CategoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->slug,
            'name' => $this->name,
            'color' => $this->color,
            'imageUrl' => $this->image_url,
            'sortOrder' => $this->sort_order,
            'postsCount' => $this->whenCounted('posts'),
        ];
    }
}
