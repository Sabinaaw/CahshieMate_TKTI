<?php

namespace Database\Seeders;

use App\Models\Category;
use Illuminate\Database\Seeder;

class CategorySeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            ['name' => 'Gaji', 'type' => 'income', 'icon' => 'wallet'],
            ['name' => 'Bonus', 'type' => 'income', 'icon' => 'gift'],
            ['name' => 'Usaha', 'type' => 'income', 'icon' => 'briefcase'],
            ['name' => 'Lainnya', 'type' => 'income', 'icon' => 'plus-circle'],

            ['name' => 'Makanan & Minuman', 'type' => 'expense', 'icon' => 'utensils'],
            ['name' => 'Transportasi', 'type' => 'expense', 'icon' => 'car'],
            ['name' => 'Belanja', 'type' => 'expense', 'icon' => 'shopping-bag'],
            ['name' => 'Tagihan', 'type' => 'expense', 'icon' => 'receipt'],
            ['name' => 'Kesehatan', 'type' => 'expense', 'icon' => 'heart-pulse'],
            ['name' => 'Hiburan', 'type' => 'expense', 'icon' => 'film'],
            ['name' => 'Pendidikan', 'type' => 'expense', 'icon' => 'book'],
            ['name' => 'Lainnya', 'type' => 'expense', 'icon' => 'circle-help'],
        ];

        foreach ($categories as $category) {
            Category::updateOrCreate(
                [
                    'name' => $category['name'],
                    'type' => $category['type'],
                ],
                ['icon' => $category['icon']]
            );
        }
    }
}
