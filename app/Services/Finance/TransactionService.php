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

        if (!is_numeric($amount) || $amount <= 0) {
            throw new RuntimeException(
                'Nominal transaksi tidak valid.'
            );
        }

        if (empty($categoryName)) {
            throw new RuntimeException(
                'Kategori transaksi tidak ditemukan.'
            );
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

        DB::table('ai_interactions')->insert([
            'user_id' => $user->id,
            'input_text' => $message,
            'ai_response' => json_encode([
                'intent' => 'financial_summary',
                ...$periodData,
                'income' => (int) $income,
                'expense' => (int) $expense,
                'balance' => (int) $balance,
            ], JSON_UNESCAPED_UNICODE),
            'status' => 'success',
            'error_message' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [
            'intent' => 'financial_summary',
            'income' => (int) $income,
            'expense' => (int) $expense,
            'balance' => (int) $balance,
            ...$periodData,
        ];
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

        DB::table('ai_interactions')->insert([
            'user_id' => $user->id,
            'input_text' => $message,
            'ai_response' => json_encode([
                'intent' => 'balance',
                ...$periodData,
                'income' => (int) $income,
                'expense' => (int) $expense,
                'balance' => (int) $balance,
            ], JSON_UNESCAPED_UNICODE),
            'status' => 'success',
            'error_message' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [
            'intent' => 'balance',
            'income' => (int) $income,
            'expense' => (int) $expense,
            'balance' => (int) $balance,
            ...$periodData,
        ];
    }

    private function getPeriodData(array $result): array
    {
        return [
            'period_type' => $result['period_type'] ?? 'current',
            'period_value' => max(
                1,
                (int) ($result['period_value'] ?? 1)
            ),
            'period_unit' => $result['period_unit'] ?? 'month',
            'specific_month' => (int) ($result['specific_month'] ?? 0),
            'specific_year' => (int) ($result['specific_year'] ?? 0),
        ];
    }

    private function getPeriodDates(array $result): array
    {
        $periodType = $result['period_type'] ?? 'current';
        $periodValue = max(
            1,
            (int) ($result['period_value'] ?? 1)
        );
        $periodUnit = $result['period_unit'] ?? 'month';
        $specificMonth = (int) ($result['specific_month'] ?? 0);
        $specificYear = (int) ($result['specific_year'] ?? 0);

        if ($periodType === 'current') {
            if ($periodUnit === 'day') {
                return [
                    now()->startOfDay()->toDateString(),
                    now()->endOfDay()->toDateString(),
                ];
            }

            if ($periodUnit === 'year') {
                return [
                    now()->startOfYear()->toDateString(),
                    now()->endOfYear()->toDateString(),
                ];
            }

            return [
                now()->startOfMonth()->toDateString(),
                now()->endOfMonth()->toDateString(),
            ];
        }

        if ($periodType === 'relative') {
            if ($periodUnit === 'day') {
                $date = now()->subDays($periodValue);

                return [
                    $date->copy()->startOfDay()->toDateString(),
                    $date->copy()->endOfDay()->toDateString(),
                ];
            }

            if ($periodUnit === 'year') {
                $date = now()->subYears($periodValue);

                return [
                    $date->copy()->startOfYear()->toDateString(),
                    $date->copy()->endOfYear()->toDateString(),
                ];
            }

            $date = now()->subMonths($periodValue);

            return [
                $date->copy()->startOfMonth()->toDateString(),
                $date->copy()->endOfMonth()->toDateString(),
            ];
        }

        if ($periodType === 'specific') {
            if ($specificYear < 2000) {
                $specificYear = now()->year;
            }

            if ($specificMonth >= 1 && $specificMonth <= 12) {
                $date = now()
                    ->setYear($specificYear)
                    ->setMonth($specificMonth);

                return [
                    $date->copy()->startOfMonth()->toDateString(),
                    $date->copy()->endOfMonth()->toDateString(),
                ];
            }

            $date = now()->setYear($specificYear);

            return [
                $date->copy()->startOfYear()->toDateString(),
                $date->copy()->endOfYear()->toDateString(),
            ];
        }

        if ($periodType === 'all_time') {
            return [
                '0000-01-01',
                '9999-12-31',
            ];
        }

        throw new RuntimeException('Periode tidak valid.');
    }

    private function resetTransactions(
        object $user,
        string $message,
        array $result
    ): array {
        $query = DB::table('transactions')
            ->where('user_id', $user->id);

        $periodType = $result['period_type'] ?? 'current';
        $periodValue = max(
            1,
            (int) ($result['period_value'] ?? 1)
        );
        $periodUnit = $result['period_unit'] ?? 'month';
        $specificMonth = (int) ($result['specific_month'] ?? 0);
        $specificYear = (int) ($result['specific_year'] ?? 0);

        if ($periodType === 'current') {
            if ($periodUnit === 'day') {
                $date = now()
                    ->subDays($periodValue - 1);

                $query->whereBetween(
                    'transaction_date',
                    [
                        $date->copy()->startOfDay()->toDateString(),
                        now()->endOfDay()->toDateString(),
                    ]
                );
            } elseif ($periodUnit === 'month') {
                $query->whereBetween(
                    'transaction_date',
                    [
                        now()->startOfMonth()->toDateString(),
                        now()->endOfMonth()->toDateString(),
                    ]
                );
            } elseif ($periodUnit === 'year') {
                $query->whereBetween(
                    'transaction_date',
                    [
                        now()->startOfYear()->toDateString(),
                        now()->endOfYear()->toDateString(),
                    ]
                );
            }
        } elseif ($periodType === 'relative') {
            if ($periodUnit === 'day') {
                $date = now()->subDays($periodValue);

                $query->whereBetween(
                    'transaction_date',
                    [
                        $date->copy()->startOfDay()->toDateString(),
                        $date->copy()->endOfDay()->toDateString(),
                    ]
                );
            } elseif ($periodUnit === 'month') {
                $date = now()->subMonths($periodValue);

                $query->whereBetween(
                    'transaction_date',
                    [
                        $date->copy()->startOfMonth()->toDateString(),
                        $date->copy()->endOfMonth()->toDateString(),
                    ]
                );
            } elseif ($periodUnit === 'year') {
                $date = now()->subYears($periodValue);

                $query->whereBetween(
                    'transaction_date',
                    [
                        $date->copy()->startOfYear()->toDateString(),
                        $date->copy()->endOfYear()->toDateString(),
                    ]
                );
            }
        } elseif ($periodType === 'specific') {
            if ($specificYear < 2000) {
                throw new RuntimeException(
                    'Tahun reset tidak valid.'
                );
            }

            if ($specificMonth >= 1 && $specificMonth <= 12) {
                $date = now()
                    ->setYear($specificYear)
                    ->setMonth($specificMonth);

                $query->whereBetween(
                    'transaction_date',
                    [
                        $date->copy()->startOfMonth()->toDateString(),
                        $date->copy()->endOfMonth()->toDateString(),
                    ]
                );
            } else {
                $date = now()->setYear($specificYear);

                $query->whereBetween(
                    'transaction_date',
                    [
                        $date->copy()->startOfYear()->toDateString(),
                        $date->copy()->endOfYear()->toDateString(),
                    ]
                );
            }
        } elseif ($periodType !== 'all_time') {
            throw new RuntimeException(
                'Periode reset tidak valid.'
            );
        }

        $deleted = $query->delete();

        DB::table('ai_interactions')->insert([
            'user_id' => $user->id,
            'input_text' => $message,
            'ai_response' => json_encode([
                'intent' => 'reset_transactions',
                'period_type' => $periodType,
                'period_value' => $periodValue,
                'period_unit' => $periodUnit,
                'specific_month' => $specificMonth,
                'specific_year' => $specificYear,
                'deleted' => $deleted,
            ], JSON_UNESCAPED_UNICODE),
            'status' => 'success',
            'error_message' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [
            'intent' => 'reset_transactions',
            'deleted' => $deleted,
            'period_type' => $periodType,
            'period_value' => $periodValue,
            'period_unit' => $periodUnit,
            'specific_month' => $specificMonth,
            'specific_year' => $specificYear,
        ];
    }
}

