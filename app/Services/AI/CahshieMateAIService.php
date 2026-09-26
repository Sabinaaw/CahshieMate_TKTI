<?php

namespace App\Services\AI;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class CahshieMateAIService
{
    private const OPENROUTER_URL =
        'https://openrouter.ai/api/v1/chat/completions';

    public function analyzeMessage(string $message): array
    {
        $apiKey = config('services.openrouter.api_key');

        $model = config(
            'services.openrouter.model',
            'openrouter/free'
        );

        if (empty($apiKey)) {
            throw new RuntimeException(
                'OPENROUTER_API_KEY belum dikonfigurasi.'
            );
        }

        $response = Http::withToken($apiKey)
            ->acceptJson()
            ->timeout(60)
            ->post(self::OPENROUTER_URL, [
                'model' => $model,
                'tools' => [
                    $this->toolSchema(),
                ],
                'tool_choice' => [
                    'type' => 'function',
                    'function' => [
                        'name' => 'extract_financial_data',
                    ],
                ],
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => $this->systemPrompt(),
                    ],
                    [
                        'role' => 'user',
                        'content' => $message,
                    ],
                ],
            ]);

        if ($response->failed()) {
            throw new RuntimeException(
                'OpenRouter API Error: ' . $response->body()
            );
        }

        $data = $response->json();

        $messageData =
            $data['choices'][0]['message'] ?? [];

        $toolCall =
            $messageData['tool_calls'][0] ?? null;

        if (!$toolCall) {
            throw new RuntimeException(
                'LLM tidak memberikan tool call.'
            );
        }

        $arguments =
            $toolCall['function']['arguments'] ?? null;

        if (empty($arguments)) {
            throw new RuntimeException(
                'LLM memberikan tool call tanpa arguments.'
            );
        }

        $result = json_decode(
            $arguments,
            true
        );

        if (!is_array($result)) {
            throw new RuntimeException(
                'Arguments LLM bukan JSON yang valid.'
            );
        }

        return $this->normalizeResult(
            $result,
            $message
        );
    }

    public function analyzeTransaction(string $message): array
    {
        return $this->analyzeMessage($message);
    }

    private function toolSchema(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => 'extract_financial_data',
                'description' =>
                    'Memahami transaksi, laporan keuangan, saldo, dan reset transaksi.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'intent' => [
                            'type' => 'string',
                            'enum' => [
                                'record_transaction',
                                'financial_summary',
                                'balance',
                                'reset_transactions',
                            ],
                        ],
                        'type' => [
                            'type' => 'string',
                            'enum' => [
                                'income',
                                'expense',
                                'none',
                            ],
                        ],
                        'amount' => [
                            'type' => 'integer',
                        ],
                        'category' => [
                            'type' => 'string',
                            'enum' => [
                                'Gaji',
                                'Bonus',
                                'Usaha',
                                'Makanan & Minuman',
                                'Transportasi',
                                'Belanja',
                                'Tagihan',
                                'Kesehatan',
                                'Hiburan',
                                'Pendidikan',
                                'Lainnya',
                                'none',
                            ],
                        ],
                        'description' => [
                            'type' => 'string',
                        ],
                        'period_type' => [
                            'type' => 'string',
                            'enum' => [
                                'current',
                                'relative',
                                'specific',
                                'all_time',
                            ],
                        ],
                        'period_value' => [
                            'type' => 'integer',
                        ],
                        'period_unit' => [
                            'type' => 'string',
                            'enum' => [
                                'day',
                                'month',
                                'year',
                                'none',
                            ],
                        ],
                        'specific_month' => [
                            'type' => 'integer',
                        ],
                        'specific_year' => [
                            'type' => 'integer',
                        ],
                    ],
                    'required' => [
                        'intent',
                        'type',
                        'amount',
                        'category',
                        'description',
                        'period_type',
                        'period_value',
                        'period_unit',
                        'specific_month',
                        'specific_year',
                    ],
                ],
            ],
        ];
    }

    private function normalizeResult(
        array $result,
        string $message
    ): array {
        $allowedIntents = [
            'record_transaction',
            'financial_summary',
            'balance',
            'reset_transactions',
        ];

        $intent = $result['intent'] ?? null;

        if (!in_array($intent, $allowedIntents, true)) {
            throw new RuntimeException(
                'Intent dari LLM tidak valid: ' .
                ($intent ?? 'null')
            );
        }

        $type = $result['type'] ?? 'none';

        if (!in_array($type, [
            'income',
            'expense',
            'none',
        ], true)) {
            $type = 'none';
        }

        $amount = $result['amount'] ?? 0;

        if (!is_numeric($amount)) {
            $amount = 0;
        }

        $amount = abs((int) $amount);

        $allowedCategories = [
            'Gaji',
            'Bonus',
            'Usaha',
            'Makanan & Minuman',
            'Transportasi',
            'Belanja',
            'Tagihan',
            'Kesehatan',
            'Hiburan',
            'Pendidikan',
            'Lainnya',
            'none',
        ];

        $category = $result['category'] ?? 'none';

        if (!in_array($category, $allowedCategories, true)) {
            $category = 'Lainnya';
        }

        $description =
            is_string($result['description'] ?? null)
                ? $result['description']
                : '';

        $periodType =
            $result['period_type'] ?? 'current';

        if (!in_array($periodType, [
            'current',
            'relative',
            'specific',
            'all_time',
        ], true)) {
            $periodType = 'current';
        }

        $periodValue =
            is_numeric($result['period_value'] ?? null)
                ? (int) $result['period_value']
                : 0;

        $periodValue = max(0, $periodValue);

        $periodUnit =
            $result['period_unit'] ?? 'none';

        if (!in_array($periodUnit, [
            'day',
            'month',
            'year',
            'none',
        ], true)) {
            $periodUnit = 'none';
        }

        $specificMonth =
            is_numeric($result['specific_month'] ?? null)
                ? (int) $result['specific_month']
                : 0;

        if ($specificMonth < 1 || $specificMonth > 12) {
            $specificMonth = 0;
        }

        $specificYear =
            is_numeric($result['specific_year'] ?? null)
                ? (int) $result['specific_year']
                : 0;

        if ($specificYear < 2000 || $specificYear > 2100) {
            $specificYear = 0;
        }

        $messageLower = mb_strtolower(
            trim($message),
            'UTF-8'
        );

        $hasTransactionAmount = $amount > 0;

        $hasFinancialQuery =
            preg_match(
                '/\b(pemasukan|pengeluaran|saldo|keuangan|transaksi|rekap|ringkasan|berapa|total)\b/u',
                $messageLower
            );

        if (
            $intent === 'record_transaction' &&
            !$hasTransactionAmount
        ) {
            if ($hasFinancialQuery) {
                $intent = $this->detectFinancialIntent(
                    $messageLower
                );

                $type = 'none';
                $amount = 0;
                $category = 'none';
            } else {
                throw new RuntimeException(
                    'Pesan tidak dikenali sebagai transaksi atau laporan keuangan.'
                );
            }
        }

        if (
            $intent === 'record_transaction' &&
            $hasTransactionAmount
        ) {
            $periodType = 'current';
            $periodValue = 1;
            $periodUnit = 'day';
            $specificMonth = 0;
            $specificYear = 0;
        }

        $monthFromMessage =
            $this->detectMonth($messageLower);

        $hasThisYear =
            preg_match(
                '/\btahun\s+ini\b/u',
                $messageLower
            );

        $hasLastYear =
            preg_match(
                '/\btahun\s+lalu\b/u',
                $messageLower
            );

        $hasExplicitYear =
            preg_match(
                '/\b(20\d{2})\b/u',
                $messageLower,
                $yearMatch
            );

        if (
            $intent !== 'record_transaction' &&
            $monthFromMessage !== 0
        ) {
            $periodType = 'specific';
            $specificMonth = $monthFromMessage;

            if ($hasExplicitYear) {
                $specificYear = (int) $yearMatch[1];
            } elseif ($hasThisYear) {
                $specificYear = now()->year;
            } elseif ($hasLastYear) {
                $specificYear = now()->year - 1;
            } else {
                $specificYear = now()->year;
            }

            $periodValue = 1;
            $periodUnit = 'month';
        }

        if (
            $intent !== 'record_transaction' &&
            $hasThisYear &&
            $monthFromMessage === 0
        ) {
            $periodType = 'specific';
            $specificMonth = 0;
            $specificYear = now()->year;
            $periodValue = 1;
            $periodUnit = 'year';
        }

        if (
            $intent !== 'record_transaction' &&
            $hasLastYear &&
            $monthFromMessage === 0
        ) {
            $periodType = 'specific';
            $specificMonth = 0;
            $specificYear = now()->year - 1;
            $periodValue = 1;
            $periodUnit = 'year';
        }

        if (
            $intent !== 'record_transaction' &&
            $hasExplicitYear &&
            !$hasThisYear &&
            !$hasLastYear &&
            $monthFromMessage === 0
        ) {
            $periodType = 'specific';
            $specificMonth = 0;
            $specificYear = (int) $yearMatch[1];
            $periodValue = 1;
            $periodUnit = 'year';
        }

        if ($intent === 'reset_transactions') {
            $type = 'none';
            $amount = 0;
            $category = 'none';

            if ($periodType === 'current') {
                if ($periodValue === 0) {
                    $periodValue = 1;
                }

                if ($periodUnit === 'none') {
                    $periodUnit = 'month';
                }
            }

            if ($monthFromMessage !== 0) {
                $periodType = 'specific';
                $specificMonth = $monthFromMessage;

                if ($hasExplicitYear) {
                    $specificYear = (int) $yearMatch[1];
                } elseif ($hasThisYear) {
                    $specificYear = now()->year;
                } elseif ($hasLastYear) {
                    $specificYear = now()->year - 1;
                } else {
                    $specificYear = now()->year;
                }

                $periodValue = 1;
                $periodUnit = 'month';
            }
        }

        return [
            'intent' => $intent,
            'type' => $type,
            'amount' => $amount,
            'category' => $category,
            'description' => $description,
            'period_type' => $periodType,
            'period_value' => $periodValue,
            'period_unit' => $periodUnit,
            'specific_month' => $specificMonth,
            'specific_year' => $specificYear,
        ];
    }

    private function detectMonth(string $message): int
    {
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

        foreach ($months as $name => $number) {
            if (preg_match(
                '/\b' . $name . '\b/u',
                $message
            )) {
                return $number;
            }
        }

        return 0;
    }

    private function detectFinancialIntent(string $message): string
    {
        if (
            preg_match(
                '/\b(saldo|duit|uang|sisa uang)\b/u',
                $message
            )
        ) {
            return 'balance';
        }

        return 'financial_summary';
    }

    private function systemPrompt(): string
    {
        $currentDate = now()->toDateString();
        $currentYear = now()->year;
        $previousYear = $currentYear - 1;

        return <<<PROMPT
Kamu adalah CahshieMate, asisten keuangan pribadi.

Tanggal sekarang: {$currentDate}

Tahun sekarang: {$currentYear}

Tahun lalu: {$previousYear}

Pahami pesan pengguna dalam bahasa Indonesia secara natural,
termasuk bahasa santai, slang, singkatan, dan typo ringan.

==================================================
INTENT
==================================================

Gunakan hanya:

record_transaction
financial_summary
balance
reset_transactions

==================================================
TRANSAKSI
==================================================

Gunakan record_transaction jika pengguna ingin mencatat
pemasukan atau pengeluaran DAN terdapat nominal transaksi.

income = uang masuk.

expense = uang keluar.

Jika tidak ada nominal transaksi, jangan gunakan
record_transaction.

Kategori income:

Gaji
Bonus
Usaha
Lainnya

Kategori expense:

Makanan & Minuman
Transportasi
Belanja
Tagihan
Kesehatan
Hiburan
Pendidikan
Lainnya

Jangan membuat kategori baru.

==================================================
NOMINAL
==================================================

25 ribu = 25000
25k = 25000
25 rb = 25000
25.000 = 25000
Rp25.000 = 25000
1 juta = 1000000
1 jt = 1000000
1,5 juta = 1500000
750 ribu = 750000
750k = 750000

Jangan mengarang nominal.

Jika bukan transaksi:

amount = 0
type = none
category = none

==================================================
PERIODE
==================================================

Hari ini:

current / 1 / day

Bulan ini:

current / 1 / month

Tahun ini:

current / 1 / year

3 hari terakhir:

relative / 3 / day

3 bulan terakhir:

relative / 3 / month

1 tahun terakhir:

relative / 1 / year

==================================================
BULAN
==================================================

Januari = 1
Februari = 2
Maret = 3
April = 4
Mei = 5
Juni = 6
Juli = 7
Agustus = 8
September = 9
Oktober = 10
November = 11
Desember = 12

Jika pengguna menyebut nama bulan tanpa tahun,
selalu gunakan tahun sekarang, yaitu {$currentYear}.

Contoh:

"pengeluaran bulan Agustus"

period_type = specific
specific_month = 8
specific_year = {$currentYear}

"pengeluaran bulan Oktober"

period_type = specific
specific_month = 10
specific_year = {$currentYear}

Jika pengguna mengatakan "tahun ini",
gunakan {$currentYear}.

Jika pengguna mengatakan "tahun lalu",
gunakan {$previousYear}.

Contoh:

"pengeluaran bulan Oktober tahun ini"

period_type = specific
specific_month = 10
specific_year = {$currentYear}

"pengeluaran bulan Oktober tahun lalu"

period_type = specific
specific_month = 10
specific_year = {$previousYear}

Jika pengguna memberikan tahun secara eksplisit,
gunakan tahun tersebut.

Contoh:

"resume September 2026"

period_type = specific
specific_month = 9
specific_year = 2026

==================================================
TAHUN
==================================================

"pengeluaran tahun 2026"

period_type = specific
specific_month = 0
specific_year = 2026
period_unit = year

"pengeluaran tahun ini"

period_type = specific
specific_month = 0
specific_year = {$currentYear}
period_unit = year

"pengeluaran tahun lalu"

period_type = specific
specific_month = 0
specific_year = {$previousYear}
period_unit = year

==================================================
SEMUA TRANSAKSI
==================================================

"semua transaksi"

"total sepanjang waktu"

"total semua pengeluaran"

period_type = all_time

==================================================
RESET
==================================================

Gunakan reset_transactions jika pengguna ingin
menghapus transaksi.

"reset transaksi"

"reset pendapatan dan pengeluaran"

"hapus transaksi bulan ini"

"mulai ulang transaksi bulan ini"

Jika hanya mengatakan "reset":

period_type = current
period_value = 1
period_unit = month

"reset pendapatan dan pengeluaran":

intent = reset_transactions
period_type = current
period_value = 1
period_unit = month

"reset transaksi September":

period_type = specific
specific_month = 9
specific_year = {$currentYear}

"reset transaksi September 2026":

period_type = specific
specific_month = 9
specific_year = 2026

"reset transaksi tahun 2026":

period_type = specific
specific_month = 0
specific_year = 2026
period_unit = year

"reset semua transaksi":

period_type = all_time

Untuk reset:

type = none
amount = 0
category = none

==================================================
LAPORAN
==================================================

Gunakan financial_summary untuk laporan keuangan.

Contoh:

"total pengeluaran ku berapa?"

"pengeluaran bulan ini berapa?"

"berapa pemasukan bulan ini?"

"rekap keuangan aku"

"pengeluaran 3 bulan terakhir berapa?"

"resume pengeluaran bulan September"

"total pengeluaran September 2026"

"berapa pengeluaran tahun 2026?"

Untuk laporan:

type = none
amount = 0
category = none

==================================================
SALDO
==================================================

Gunakan balance untuk:

"saldo aku berapa?"

"duit aku tinggal berapa?"

"uangku sekarang berapa?"

"sisa uangku berapa?"

Untuk saldo:

type = none
amount = 0
category = none

==================================================
ATURAN PENTING
==================================================

Jangan menghitung total.

Jangan menghitung saldo.

Jangan mengarang data.

Laravel akan melakukan perhitungan berdasarkan database.

AI hanya memahami maksud pengguna dan mengambil
informasi dari pesan.

Jika pengguna hanya menyebut tahun atau bulan tanpa
nominal transaksi, jangan gunakan record_transaction.

Selalu gunakan function:

extract_financial_data

PROMPT;
    }
}
