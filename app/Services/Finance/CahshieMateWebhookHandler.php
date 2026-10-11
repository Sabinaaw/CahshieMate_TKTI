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

        $outputText =
            $data['choices'][0]['message']['content'] ?? null;

        if (empty($outputText)) {
            throw new RuntimeException(
                'LLM tidak memberikan hasil.'
            );
        }

        $outputText = trim($outputText);

        if (str_starts_with($outputText, '```')) {
            $outputText = preg_replace(
                '/^```(?:json)?\s*|\s*```$/i',
                '',
                $outputText
            );

            $outputText = trim($outputText);
        }

        $result = json_decode($outputText, true);

        if (!is_array($result)) {
            throw new RuntimeException(
                'Response LLM bukan JSON yang valid: ' .
                $outputText
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

    private function normalizeResult(
        array $result,
        string $message
    ): array {
        $allowedIntents = [
            'record_transaction',
            'financial_summary',
            'balance',
            'category_summary',
            'reset_transactions',
        ];

        $intent = $result['intent'] ?? null;

        if (!in_array($intent, $allowedIntents, true)) {
            throw new RuntimeException(
                'Intent dari LLM tidak valid.'
            );
        }

        $type = $result['type'] ?? null;

        if (
            $type !== null &&
            !in_array($type, ['income', 'expense'], true)
        ) {
            $type = null;
        }

        $amount = $result['amount'] ?? null;

        if ($amount !== null) {
            $amount = is_numeric($amount)
                ? (int) $amount
                : null;
        }

        $allowedCategories = [
            'Gaji',
            'Bonus',
            'Usaha',
            'Makanan & Minuman',
            'Fashion',
            'Transportasi',
            'Belanja',
            'Tagihan',
            'Kesehatan',
            'Hiburan',
            'Pendidikan',
            'Lainnya',
        ];

        $category = $result['category'] ?? null;

        if (
            $category !== null &&
            !in_array($category, $allowedCategories, true)
        ) {
            $category = 'Lainnya';
        }

        $breakdown = filter_var(
            $result['breakdown'] ?? false,
            FILTER_VALIDATE_BOOLEAN
        );

        $allowedPeriods = [
            'today',
            'yesterday',
            'this_week',
            'last_week',
            'this_month',
            'last_month',
            'this_year',
            'last_30_days',
            'all_time',
            'custom',
        ];

        $period = $result['period'] ?? 'this_month';

        if (!in_array($period, $allowedPeriods, true)) {
            $period = 'this_month';
        }

        $startDate = $result['start_date'] ?? null;
        $endDate = $result['end_date'] ?? null;

        if ($period !== 'custom') {
            $startDate = null;
            $endDate = null;
        }

        $specificMonth = $this->detectSpecificMonth($message);

        if ($specificMonth) {
            $period = 'custom';
            $startDate = $specificMonth['start_date'];
            $endDate = $specificMonth['end_date'];
        }

        $description = $result['description'] ?? null;

        if (
            in_array(
                $intent,
                [
                    'financial_summary',
                    'category_summary',
                    'balance',
                    'reset_transactions',
                ],
                true
            )
        ) {
            $description = null;
        }

        if ($intent === 'financial_summary') {
            $category = null;
            $amount = null;

            if ($this->isBreakdownRequest($message)) {
                $breakdown = true;
                $type = 'expense';
            }
        }

        if ($intent === 'category_summary') {
            $type = 'expense';
            $amount = null;
            $breakdown = false;
        }

        if ($intent === 'balance') {
            $type = null;
            $amount = null;
            $category = null;
            $breakdown = false;
            $period = 'all_time';
            $startDate = null;
            $endDate = null;
        }

        if ($intent === 'record_transaction') {
            $breakdown = false;
            $startDate = null;
            $endDate = null;
        }

        if ($intent === 'reset_transactions') {
            $type = null;
            $amount = null;
            $category = null;
            $breakdown = false;
        }

        return [
            'intent' => $intent,
            'type' => $type,
            'amount' => $amount,
            'category' => $category,
            'breakdown' => $breakdown,
            'period' => $period,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'description' => $description,
        ];
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
        ];

        foreach ($patterns as $pattern) {
            if (str_contains($message, $pattern)) {
                return true;
            }
        }

        return false;
    }

    private function detectSpecificMonth(
        string $message
    ): ?array {
        $message = mb_strtolower(
            trim($message)
        );

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

    private function systemPrompt(): string
    {
        return <<<'PROMPT'
Kamu adalah CahshieMate, asisten keuangan pribadi.

Pahami pesan pengguna dalam bahasa Indonesia secara natural. Pengguna dapat menggunakan bahasa formal, santai, slang, singkatan, typo ringan, atau kalimat pendek.

Gunakan salah satu intent:

1. record_transaction
2. financial_summary
3. balance
4. category_summary
5. reset_transactions

==================================================
RECORD_TRANSACTION
==================================================

Gunakan record_transaction jika pengguna ingin mencatat pemasukan atau pengeluaran.

Contoh:

"tadi makan 25 ribu"

"barusan beli kopi 18k"

"bayar listrik 300rb"

"gajian masuk 5 juta"

"aku dapat bonus 500 ribu"

"catat pengeluaran 50k buat hiburan"

"pengeluaran untuk hiburan"

"pengeluaran buat makanan"

"aku ada pengeluaran transportasi"

"ada pemasukan dari usaha"

Jika pengguna ingin mencatat transaksi tetapi belum menyebut nominal:

amount = null

Contoh:

"pengeluaran untuk hiburan"

{
    "intent": "record_transaction",
    "type": "expense",
    "amount": null,
    "category": "Hiburan",
    "breakdown": false,
    "period": "this_month",
    "start_date": null,
    "end_date": null,
    "description": "Pengeluaran hiburan"
}

==================================================
FINANCIAL_SUMMARY
==================================================

Gunakan financial_summary jika pengguna ingin mengetahui total pemasukan atau pengeluaran yang sudah tercatat.

Contoh:

"total pengeluaran bulan ini berapa?"

"total pengeluaran saya?"

"berapa pemasukan bulan ini?"

"rekap keuangan saya"

"laporan keuangan bulan ini"

"berapa total uang yang keluar?"

Jika pengguna hanya meminta total:

breakdown = false

Jika pengguna meminta mengetahui uang digunakan untuk apa saja, rincian, rekap, atau pengeluaran berdasarkan kategori:

breakdown = true

Contoh:

"ringkas pengeluaran aku bulan ini"

"rekap semua pengeluaran bulan ini"

"bulan ini uangku habis buat apa aja?"

"bulan ini uang ku habis buat apa aja?"

"uangku bulan ini habis untuk apa saja?"

"pengeluaran bulan ini buat apa aja?"

"pengeluaran berdasarkan kategori bulan ini"

"tampilkan rincian pengeluaran"

"tampilkan pengeluaran per kategori"

Semua contoh di atas harus menghasilkan:

{
    "intent": "financial_summary",
    "type": "expense",
    "amount": null,
    "category": null,
    "breakdown": true,
    "period": "this_month",
    "start_date": null,
    "end_date": null,
    "description": null
}

==================================================
CATEGORY_SUMMARY
==================================================

Gunakan category_summary jika pengguna ingin mengetahui jumlah pengeluaran dari SATU kategori tertentu.

Contoh:

"pengeluaran buat hiburan berapa?"

"aku habis berapa buat hiburan?"

"berapa pengeluaran untuk makanan?"

"bulan ini aku keluar berapa untuk makan?"

"berapa uang yang aku habiskan buat transportasi?"

"total belanja fashion berapa?"

Contoh output:

{
    "intent": "category_summary",
    "type": "expense",
    "amount": null,
    "category": "Hiburan",
    "breakdown": false,
    "period": "this_month",
    "start_date": null,
    "end_date": null,
    "description": null
}

==================================================
RESET_TRANSACTIONS
==================================================

Gunakan reset_transactions jika pengguna ingin menghapus atau mereset transaksi keuangan.

Contoh:

"reset keuangan bulan ini"

"hapus transaksi bulan ini"

"hapus semua transaksi bulan ini"

"reset transaksi bulan ini"

"bersihkan transaksi bulan ini"

"hapus keuangan bulan ini"

Jika pengguna menyebut periode tertentu, gunakan periode tersebut.

Contoh:

"reset keuangan bulan ini"

{
    "intent": "reset_transactions",
    "type": null,
    "amount": null,
    "category": null,
    "breakdown": false,
    "period": "this_month",
    "start_date": null,
    "end_date": null,
    "description": null
}

Jika pengguna menyebut bulan dan tahun tertentu seperti:

"reset keuangan Desember 2025"

"hapus transaksi bulan Desember 2025"

gunakan:

{
    "intent": "reset_transactions",
    "type": null,
    "amount": null,
    "category": null,
    "breakdown": false,
    "period": "custom",
    "start_date": "2025-12-01",
    "end_date": "2025-12-31",
    "description": null
}

==================================================
RECORD VS CATEGORY_SUMMARY
==================================================

"pengeluaran untuk hiburan"

→ record_transaction

"catat pengeluaran untuk hiburan"

→ record_transaction

"catat pengeluaran 50 ribu untuk hiburan"

→ record_transaction

"berapa pengeluaran untuk hiburan?"

→ category_summary

"pengeluaran untuk hiburan berapa?"

→ category_summary

"aku habis berapa buat hiburan?"

→ category_summary

"bulan ini aku keluar berapa buat hiburan?"

→ category_summary

"aku tadi nongkrong 50k"

→ record_transaction

"aku tadi keluar 50k buat nongkrong"

→ record_transaction

==================================================
BALANCE
==================================================

Gunakan balance jika pengguna ingin mengetahui saldo atau uang tersisa.

Contoh:

"saldo aku berapa?"

"duit aku tinggal berapa?"

"uangku sekarang berapa?"

"sisa uangku berapa?"

"sekarang aku punya uang berapa?"

==================================================
TYPE
==================================================

income = uang masuk.

expense = uang keluar.

Expense biasanya ditandai dengan:

keluar, bayar, beli, belanja, habis, pengeluaran, mengeluarkan, digunakan.

Income biasanya ditandai dengan:

masuk, dapat, menerima, gajian, pemasukan, dibayar.

==================================================
KATEGORI
==================================================

Kategori income:

- Gaji
- Bonus
- Usaha
- Lainnya

Kategori expense:

- Makanan & Minuman
- Fashion
- Transportasi
- Belanja
- Tagihan
- Kesehatan
- Hiburan
- Pendidikan
- Lainnya

Jangan membuat kategori baru.

Gunakan kategori yang paling sesuai.

Contoh:

"nongkrong sama teman"

→ Hiburan

"nonton bioskop"

→ Hiburan

"main game"

→ Hiburan

"makan nasi goreng"

→ Makanan & Minuman

"beli kopi"

→ Makanan & Minuman

"beli baju"

→ Fashion

"naik ojek"

→ Transportasi

"beli laptop"

→ Belanja

"bayar listrik"

→ Tagihan

"berobat"

→ Kesehatan

"beli obat"

→ Kesehatan

"bayar kursus"

→ Pendidikan

==================================================
NOMINAL
==================================================

Pahami format nominal Indonesia.

25 ribu = 25000

25k = 25000

25 rb = 25000

25 ribuan = 25000

25.000 = 25000

Rp25.000 = 25000

1 juta = 1000000

1 jt = 1000000

1,5 juta = 1500000

750 ribu = 750000

750k = 750000

Jangan mengarang nominal.

Jika nominal tidak disebutkan:

amount = null

==================================================
PERIODE
==================================================

Gunakan salah satu:

today

yesterday

this_week

last_week

this_month

last_month

this_year

last_30_days

all_time

custom

Gunakan:

"hari ini"

→ today

"kemarin"

→ yesterday

"minggu ini"

→ this_week

"minggu lalu"

→ last_week

"bulan ini"

→ this_month

"bulan lalu"

→ last_month

"tahun ini"

→ this_year

"30 hari terakhir"

→ last_30_days

"semua transaksi"

→ all_time

Jika pengguna menyebut bulan dan tahun tertentu:

period = custom

Gunakan format YYYY-MM-DD.

Contoh:

"Desember 2025"

→ period = custom

→ start_date = "2025-12-01"

→ end_date = "2025-12-31"

"Januari 2026"

→ period = custom

→ start_date = "2026-01-01"

→ end_date = "2026-01-31"

Jika pengguna menyebut tanggal mulai dan tanggal akhir:

period = custom

Gunakan format YYYY-MM-DD.

==================================================
BREAKDOWN
==================================================

breakdown = true jika pengguna meminta:

- rincian semua pengeluaran
- pengeluaran berdasarkan kategori
- rekap pengeluaran
- ringkasan pengeluaran per kategori
- uang habis buat apa saja
- uang digunakan untuk apa saja
- pengeluaran buat apa saja

Contoh:

"bulan ini uangku habis buat apa aja?"

"bulan ini uang ku habis buat apa aja?"

"uangku bulan ini habis untuk apa?"

"pengeluaran bulan ini buat apa aja?"

"rekap pengeluaran bulan ini"

"ringkas pengeluaran bulan ini"

"tampilkan pengeluaran per kategori"

Jika hanya meminta angka total:

breakdown = false

Contoh:

"total pengeluaran bulan ini berapa?"

Jika pengguna meminta satu kategori tertentu, gunakan category_summary dan breakdown = false.

==================================================
ATURAN
==================================================

1. Jangan mengarang nominal.

2. Jangan menghitung total.

3. Jangan menghitung saldo.

4. Jangan membuat kategori baru.

5. Jangan menganggap kata "pengeluaran" otomatis sebagai financial_summary.

6. Pernyataan untuk mencatat transaksi → record_transaction.

7. Pertanyaan jumlah satu kategori → category_summary.

8. Total semua transaksi → financial_summary.

9. Rincian semua kategori → financial_summary dengan breakdown = true.

10. Pertanyaan saldo → balance.

11. Permintaan menghapus atau mereset transaksi → reset_transactions.

12. Laravel melakukan perhitungan berdasarkan database.

13. Hanya kembalikan JSON valid.

14. Jangan memberikan teks di luar JSON.

15. Jika pengguna menyebut bulan dan tahun tertentu, gunakan period = custom dan tentukan start_date serta end_date bulan tersebut.

==================================================
CONTOH UTAMA
==================================================

Input:

"bulan ini uang ku habis buat apa aja?"

Output:

{
    "intent": "financial_summary",
    "type": "expense",
    "amount": null,
    "category": null,
    "breakdown": true,
    "period": "this_month",
    "start_date": null,
    "end_date": null,
    "description": null
}

Input:

"total pengeluaran bulan ini?"

Output:

{
    "intent": "financial_summary",
    "type": "expense",
    "amount": null,
    "category": null,
    "breakdown": false,
    "period": "this_month",
    "start_date": null,
    "end_date": null,
    "description": null
}

Input:

"pengeluaran buat hiburan berapa?"

Output:

{
    "intent": "category_summary",
    "type": "expense",
    "amount": null,
    "category": "Hiburan",
    "breakdown": false,
    "period": "this_month",
    "start_date": null,
    "end_date": null,
    "description": null
}

Input:

"rekap keuangan bulan desember 2025"

Output:

{
    "intent": "financial_summary",
    "type": null,
    "amount": null,
    "category": null,
    "breakdown": false,
    "period": "custom",
    "start_date": "2025-12-01",
    "end_date": "2025-12-31",
    "description": null
}

Input:

"reset keuangan bulan ini"

Output:

{
    "intent": "reset_transactions",
    "type": null,
    "amount": null,
    "category": null,
    "breakdown": false,
    "period": "this_month",
    "start_date": null,
    "end_date": null,
    "description": null
}

PROMPT;
    }
}
