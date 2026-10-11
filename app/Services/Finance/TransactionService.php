<?php

namespace App\Services\Finance;

use App\Services\AI\CahshieMateAIService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;

class TransactionService
{
    public function processTelegramMessage(
        string $telegramId,
        string $message
    ): array {
        return DB::transaction(function () use ($telegramId, $message) {
            $user = DB::table('users')
                ->where('telegram_id', $telegramId)
                ->first();

            if (!$user) {
                $userId = DB::table('users')->insertGetId([
                    'name' => 'Telegram User ' . $telegramId,
                    'email' => 'telegram_' . $telegramId . '@cahshiemate.local',
                    'password' => Hash::make(Str::random(32)),
                    'telegram_id' => $telegramId,
                    'minimum_balance' => 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $user = DB::table('users')
                    ->where('id', $userId)
                    ->first();
            }

            $ai = app(CahshieMateAIService::class);
            $result = $ai->analyzeMessage($message);

            $specificMonth = $this->detectSpecificMonth($message);

            if ($specificMonth) {
                $result['period'] = 'custom';
                $result['start_date'] = $specificMonth['start_date'];
                $result['end_date'] = $specificMonth['end_date'];
            }

            if ($this->isCategorySummaryRequest($message)) {
                $category = $this->detectCategoryFromMessage($message);

                if ($category) {
                    $result['intent'] = 'category_summary';
                    $result['type'] = 'expense';
                    $result['category'] = $category;
                    $result['amount'] = null;
                    $result['breakdown'] = false;
                }
            }

            $intent = $result['intent'] ?? null;

            if ($intent === 'record_transaction') {
                return $this->saveTransaction(
                    $user,
                    $message,
                    $result
                );
            }

            if ($intent === 'financial_summary') {
                return $this->financialSummary(
                    $user,
                    $message,
                    $result
                );
            }

            if ($intent === 'category_summary') {
                return $this->categorySummary(
                    $user,
                    $message,
                    $result
                );
            }

            if ($intent === 'balance') {
                return $this->balance(
                    $user,
                    $message,
                    $result
                );
            }

            if ($intent === 'reset_transactions') {
                return $this->resetTransactions(
                    $user,
                    $message,
                    $result
                );
            }

            throw new RuntimeException(
                'Intent tidak dikenali: ' . ($intent ?? 'null')
            );
        });
    }

    private function saveTransaction(
        object $user,
        string $message,
        array $result
    ): array {
        $type = $result['type'] ?? null;
        $amount = $result['amount'] ?? null;
        $categoryName = $result['category'] ?? null;
        $description = $result['description'] ?? null;

        if (!in_array($type, ['income', 'expense'], true)) {
            throw new RuntimeException(
                'Jenis transaksi tidak valid.'
            );
        }

        if ($amount === null || !is_numeric($amount) || $amount <= 0) {
            return [
                'intent' => 'record_transaction',
                'user_id' => $user->id,
                'type' => $type,
                'amount' => null,
                'category' => $categoryName,
                'description' => $description,
            ];
        }

        if (empty($categoryName)) {
            $categoryName = 'Lainnya';
        }

        if (empty($description)) {
            $description = $message;
        }

        $category = DB::table('categories')
            ->where('name', $categoryName)
            ->where('type', $type)
            ->first();

        if (!$category) {
            $category = DB::table('categories')
                ->where('name', 'Lainnya')
                ->where('type', $type)
                ->first();
        }

        if (!$category) {
            throw new RuntimeException(
                'Kategori tidak tersedia di database.'
            );
        }

        $transactionId = DB::table('transactions')->insertGetId([
            'user_id' => $user->id,
            'category_id' => $category->id,
            'type' => $type,
            'amount' => (int) $amount,
            'description' => $description,
            'transaction_date' => now()->toDateString(),
            'source' => 'text',
            'receipt_path' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('ai_interactions')->insert([
            'user_id' => $user->id,
            'input_text' => $message,
            'ai_response' => json_encode(
                $result,
                JSON_UNESCAPED_UNICODE
            ),
            'status' => 'success',
            'error_message' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [
            'intent' => 'record_transaction',
            'transaction_id' => $transactionId,
            'user_id' => $user->id,
            'type' => $type,
            'amount' => (int) $amount,
            'category' => $category->name,
            'description' => $description,
        ];
    }

    private function financialSummary(
        object $user,
        string $message,
        array $result
    ): array {
        $isBreakdown = $this->isBreakdownRequest($message);

        if ($isBreakdown) {
            $result['breakdown'] = true;
            $result['type'] = 'expense';
        }

        [$startDate, $endDate] = $this->getPeriodDates($result);

        $income = DB::table('transactions')
            ->where('user_id', $user->id)
            ->where('type', 'income')
            ->whereBetween(
                'transaction_date',
                [$startDate, $endDate]
            )
            ->sum('amount');

        $expense = DB::table('transactions')
            ->where('user_id', $user->id)
            ->where('type', 'expense')
            ->whereBetween(
                'transaction_date',
                [$startDate, $endDate]
            )
            ->sum('amount');

        $balance = $income - $expense;

        $breakdownData = [];

        if (($result['breakdown'] ?? false) === true) {
            $breakdownData = DB::table('transactions')
                ->join(
                    'categories',
                    'transactions.category_id',
                    '=',
                    'categories.id'
                )
                ->where('transactions.user_id', $user->id)
                ->where('transactions.type', 'expense')
                ->whereBetween(
                    'transactions.transaction_date',
                    [$startDate, $endDate]
                )
                ->select(
                    'categories.name as category',
                    DB::raw(
                        'SUM(transactions.amount) as amount'
                    )
                )
                ->groupBy(
                    'categories.id',
                    'categories.name'
                )
                ->orderByDesc('amount')
                ->get()
                ->map(function ($item) {
                    return [
                        'category' => $item->category,
                        'amount' => (int) $item->amount,
                    ];
                })
                ->values()
                ->toArray();
        }

        $periodData = $this->getPeriodData($result);

        $response = [
            'intent' => 'financial_summary',
            'income' => (int) $income,
            'expense' => (int) $expense,
            'balance' => (int) $balance,
            'breakdown' => ($result['breakdown'] ?? false) === true,
            'breakdown_data' => $breakdownData,
            ...$periodData,
        ];

        DB::table('ai_interactions')->insert([
            'user_id' => $user->id,
            'input_text' => $message,
            'ai_response' => json_encode(
                $response,
                JSON_UNESCAPED_UNICODE
            ),
            'status' => 'success',
            'error_message' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $response;
    }

    private function categorySummary(
        object $user,
        string $message,
        array $result
    ): array {
        [$startDate, $endDate] = $this->getPeriodDates($result);

        $categoryName = $result['category'] ?? null;

        $query = DB::table('transactions')
            ->join(
                'categories',
                'transactions.category_id',
                '=',
                'categories.id'
            )
            ->where('transactions.user_id', $user->id)
            ->where('transactions.type', 'expense')
            ->whereBetween(
                'transactions.transaction_date',
                [$startDate, $endDate]
            );

        if (!empty($categoryName)) {
            $query->where(
                'categories.name',
                $categoryName
            );
        }

        $totalExpense = (int) $query->sum(
            'transactions.amount'
        );

        $periodData = $this->getPeriodData($result);

        $response = [
            'intent' => 'category_summary',
            'category' => $categoryName,
            'expense' => $totalExpense,
            'total' => $totalExpense,
            ...$periodData,
        ];

        DB::table('ai_interactions')->insert([
            'user_id' => $user->id,
            'input_text' => $message,
            'ai_response' => json_encode(
                $response,
                JSON_UNESCAPED_UNICODE
            ),
            'status' => 'success',
            'error_message' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $response;
    }

    private function balance(
        object $user,
        string $message,
        array $result
    ): array {
        [$startDate, $endDate] = $this->getPeriodDates($result);

        $income = DB::table('transactions')
            ->where('user_id', $user->id)
            ->where('type', 'income')
            ->whereBetween(
                'transaction_date',
                [$startDate, $endDate]
            )
            ->sum('amount');

        $expense = DB::table('transactions')
            ->where('user_id', $user->id)
            ->where('type', 'expense')
            ->whereBetween(
                'transaction_date',
                [$startDate, $endDate]
            )
            ->sum('amount');

        $balance = $income - $expense;

        $periodData = $this->getPeriodData($result);

        $response = [
            'intent' => 'balance',
            'income' => (int) $income,
            'expense' => (int) $expense,
            'balance' => (int) $balance,
            ...$periodData,
        ];

        DB::table('ai_interactions')->insert([
            'user_id' => $user->id,
            'input_text' => $message,
            'ai_response' => json_encode(
                $response,
                JSON_UNESCAPED_UNICODE
            ),
            'status' => 'success',
            'error_message' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $response;
    }

    private function resetTransactions(
        object $user,
        string $message,
        array $result
    ): array {
        [$startDate, $endDate] = $this->getPeriodDates($result);

        $deleted = DB::table('transactions')
            ->where('user_id', $user->id)
            ->whereBetween(
                'transaction_date',
                [$startDate, $endDate]
            )
            ->delete();

        $periodData = $this->getPeriodData($result);

        $response = [
            'intent' => 'reset_transactions',
            'deleted' => $deleted,
            ...$periodData,
        ];

        DB::table('ai_interactions')->insert([
            'user_id' => $user->id,
            'input_text' => $message,
            'ai_response' => json_encode(
                $response,
                JSON_UNESCAPED_UNICODE
            ),
            'status' => 'success',
            'error_message' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $response;
    }

    private function isCategorySummaryRequest(string $message): bool
    {
        $message = mb_strtolower(trim($message));

        $patterns = [
            'berapa pengeluaran',
            'pengeluaran saya berapa',
            'pengeluaran aku berapa',
            'pengeluaran ku berapa',
            'habis berapa untuk',
            'habis berapa buat',
            'total pengeluaran untuk',
            'total pengeluaran buat',
            'jumlah pengeluaran untuk',
            'jumlah pengeluaran buat',
        ];

        foreach ($patterns as $pattern) {
            if (str_contains($message, $pattern)) {
                return true;
            }
        }

        return false;
    }

    private function detectCategoryFromMessage(string $message): ?string
    {
        $message = mb_strtolower(trim($message));

        $categories = DB::table('categories')
            ->where('type', 'expense')
            ->pluck('name')
            ->toArray();

        foreach ($categories as $category) {
            if (str_contains(
                $message,
                mb_strtolower($category)
            )) {
                return $category;
            }
        }

        $aliases = [
            'makan' => 'Makanan & Minuman',
            'makanan' => 'Makanan & Minuman',
            'minum' => 'Makanan & Minuman',
            'minuman' => 'Makanan & Minuman',
            'transport' => 'Transportasi',
            'bensin' => 'Transportasi',
            'shopping' => 'Belanja',
            'listrik' => 'Tagihan',
            'hiburan' => 'Hiburan',
            'game' => 'Hiburan',
            'gaming' => 'Hiburan',
            'film' => 'Hiburan',
            'nonton' => 'Hiburan',
            'obat' => 'Kesehatan',
            'baju' => 'Fashion',
            'pakaian' => 'Fashion',
            'kuliah' => 'Pendidikan',
        ];

        foreach ($aliases as $keyword => $category) {
            if (str_contains($message, $keyword)) {
                return $category;
            }
        }

        return null;
    }

    private function detectSpecificMonth(
        string $message
    ): ?array {
        $message = mb_strtolower(trim($message));

        $months = [
            'januari' => 1,
            'februari' => 2,
            'maret' => 3,
            'april' => 4,
            'mei' => 5,
            'juni' => 6,
            'juli' => 7,
            'agustus' => 8,
            'september' => 9,
            'oktober' => 10,
            'november' => 11,
            'desember' => 12,
        ];

        foreach ($months as $monthName => $monthNumber) {
            $pattern = '/\b' . $monthName . '\s+(\d{4})\b/u';

            if (preg_match($pattern, $message, $matches)) {
                $year = (int) $matches[1];

                if ($year < 1900 || $year > 2999) {
                    return null;
                }

                $startDate = sprintf(
                    '%04d-%02d-01',
                    $year,
                    $monthNumber
                );

                $endDate = date(
                    'Y-m-t',
                    strtotime($startDate)
                );

                return [
                    'start_date' => $startDate,
                    'end_date' => $endDate,
                ];
            }
        }

        return null;
    }

    private function isBreakdownRequest(string $message): bool
    {
        $message = mb_strtolower(
            trim($message)
        );

        $patterns = [
            'habis buat apa',
            'habis untuk apa',
            'uangku buat apa',
            'uang ku buat apa',
            'uangku untuk apa',
            'uang ku untuk apa',
            'pengeluaran buat apa',
            'pengeluaran untuk apa',
            'pengeluaran apa saja',
            'pengeluaran apa aja',
            'buat apa aja',
            'buat apa saja',
            'untuk apa aja',
            'untuk apa saja',
            'rekap pengeluaran',
            'ringkas pengeluaran',
            'rincian pengeluaran',
            'detail pengeluaran',
            'pengeluaran berdasarkan kategori',
            'rincian berdasarkan kategori',
            'rekap berdasarkan kategori',
            'pengeluaran per kategori',
            'tampilkan pengeluaran per kategori',
        ];

        foreach ($patterns as $pattern) {
            if (str_contains($message, $pattern)) {
                return true;
            }
        }

        return false;
    }

    private function getPeriodData(array $result): array
    {
        return [
            'period' => $result['period'] ?? 'this_month',
            'start_date' => $result['start_date'] ?? null,
            'end_date' => $result['end_date'] ?? null,
        ];
    }

    private function getPeriodDates(array $result): array
    {
        $period = $result['period'] ?? 'this_month';

        if ($period === 'custom') {
            $startDate = $result['start_date'] ?? null;
            $endDate = $result['end_date'] ?? null;

            if (!$startDate || !$endDate) {
                throw new RuntimeException(
                    'Rentang tanggal tidak lengkap.'
                );
            }

            return [$startDate, $endDate];
        }

        return match ($period) {
            'today' => [
                now()->startOfDay()->toDateString(),
                now()->endOfDay()->toDateString(),
            ],

            'yesterday' => [
                now()
                    ->copy()
                    ->subDay()
                    ->startOfDay()
                    ->toDateString(),
                now()
                    ->copy()
                    ->subDay()
                    ->endOfDay()
                    ->toDateString(),
            ],

            'this_week' => [
                now()->startOfWeek()->toDateString(),
                now()->endOfWeek()->toDateString(),
            ],

            'last_week' => [
                now()
                    ->copy()
                    ->subWeek()
                    ->startOfWeek()
                    ->toDateString(),
                now()
                    ->copy()
                    ->subWeek()
                    ->endOfWeek()
                    ->toDateString(),
            ],

            'this_month' => [
                now()->startOfMonth()->toDateString(),
                now()->endOfMonth()->toDateString(),
            ],

            'last_month' => [
                now()
                    ->copy()
                    ->subMonth()
                    ->startOfMonth()
                    ->toDateString(),
                now()
                    ->copy()
                    ->subMonth()
                    ->endOfMonth()
                    ->toDateString(),
            ],

            'this_year' => [
                now()->startOfYear()->toDateString(),
                now()->endOfYear()->toDateString(),
            ],

            'last_30_days' => [
                now()
                    ->copy()
                    ->subDays(29)
                    ->startOfDay()
                    ->toDateString(),
                now()->endOfDay()->toDateString(),
            ],

            'all_time' => [
                '1900-01-01',
                '2999-12-31',
            ],

            default => [
                now()->startOfMonth()->toDateString(),
                now()->endOfMonth()->toDateString(),
            ],
        };
    }
}
