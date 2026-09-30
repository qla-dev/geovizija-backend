<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Post;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Imports the frontend's mock categories and articles (data/*.json, exported from constants.ts).
 * Idempotent: rows are matched by slug and never deleted.
 */
class ContentSeeder extends Seeder
{
    private const MONTHS = [
        'januar' => 1, 'februar' => 2, 'mart' => 3, 'april' => 4, 'maj' => 5, 'juni' => 6,
        'juli' => 7, 'august' => 8, 'septembar' => 9, 'oktobar' => 10, 'novembar' => 11, 'decembar' => 12,
    ];

    public function run(): void
    {
        $categories = json_decode(file_get_contents(__DIR__.'/data/categories.json'), true);

        foreach ($categories as $index => $category) {
            Category::updateOrCreate(['slug' => $category['id']], [
                'name' => $category['name'],
                'color' => $category['color'],
                'image_url' => $category['imageUrl'],
                'sort_order' => $index,
            ]);
        }

        $categoryIds = Category::pluck('id', 'slug');
        $posts = json_decode(file_get_contents(__DIR__.'/data/posts.json'), true);

        // The mock dates lie in the future; shift them back so they are visible, keeping their order.
        $latest = collect($posts)->map(fn ($post) => $this->parseDate($post['date']))->max();
        $shiftDays = $latest->isFuture() ? (int) ceil(now()->diffInDays($latest)) : 0;

        foreach ($posts as $post) {
            Post::updateOrCreate(['slug' => Str::slug($post['title'])], [
                'category_id' => $categoryIds[$post['categoryId']],
                'title' => $post['title'],
                'excerpt' => $post['excerpt'],
                'content' => $post['content'],
                'image_url' => $post['imageUrl'],
                'author' => $post['author'],
                'read_time' => $post['readTime'],
                'featured' => $post['featured'] ?? false,
                'published_at' => $this->parseDate($post['date'])->subDays($shiftDays),
            ]);
        }
    }

    /** Parses dates like "12. Oktobar 2026". */
    private function parseDate(string $date): Carbon
    {
        [$day, $month, $year] = preg_split('/[.\s]+/', trim($date));

        return Carbon::create((int) $year, self::MONTHS[mb_strtolower($month)], (int) $day, 8);
    }
}
