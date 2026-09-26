<?php

namespace Database\Seeders;

use App\Models\Budget;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class CahshieMateSeeder extends Seeder
{
    public function run(): void
    {
        $user = User::updateOrCreate(
            ['email' => 'demo@cahshiemate.test'],
            [
                'name' => 'Demo CahshieMate',
                'password' => Hash::make('password123'),
                'minimum_balance' => 100000,
            ]
        );

        $incomeCategory = Category::where('name', 'Gaji')
            ->where('type', 'income')
            ->firstOrFail();

        $expenseCategory = Category::where('name', 'Makanan & Minuman')
            ->where('type', 'expense')
            ->firstOrFail();

        Transaction::firstOrCreate(
            [
                'user_id' => $user->id,
                'description' => 'Contoh pemasukan gaji',
            ],
            [
                'category_id' => $incomeCategory->id,
                'type' => 'income',
                'amount' => 4000000,
                'transaction_date' => now()->toDateString(),
                'source' => 'manual',
            ]
        );

        Transaction::firstOrCreate(
            [
                'user_id' => $user->id,
                'description' => 'Contoh makan siang',
            ],
            [
                'category_id' => $expenseCategory->id,
                'type' => 'expense',
                'amount' => 25000,
                'transaction_date' => now()->toDateString(),
                'source' => 'text',
            ]
        );

        Budget::updateOrCreate(
            [
                'user_id' => $user->id,
                'category_id' => $expenseCategory->id,
                'month' => now()->month,
                'year' => now()->year,
            ],
            [
                'limit_amount' => 1000000,
            ]
        );
    }
}
