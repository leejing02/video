<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Category;
use Overtrue\Pinyin\Pinyin;

class CategorySlugFixSeeder extends Seeder
{
    public function run(): void
    {
        $pinyin = new Pinyin();

        $categories = Category::all();

        foreach ($categories as $category) {

            $slug = $pinyin->permalink($category->name ?: '');
            $baseSlug = $slug ?: 'cat';

            $count = Category::where('slug', 'like', $baseSlug.'%')
                ->where('id', '!=', $category->id)
                ->count();

            $category->slug = $count ? $baseSlug.'-'.$count : $baseSlug;
            $category->save();
        }
    }
}