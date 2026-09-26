<?php

namespace App\Services\AI;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class CahshieMateAIService
{
    private const OPENROUTER_URL =
        'https://openrouter.ai/api/v1/chat/completions';

    /**
     * Memahami pesan teks pengguna.
     */
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

        // Hapus markdown code fence jika ada
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

        return $this->normalizeResult($result);
    }

    /**
     * Alias untuk kompatibilitas kode lama.
     */
    public function analyzeTransaction(string $message): array
    {
        return $this->analyzeMessage($message);
    }

    /**
     * Normalisasi hasil AI.
     */
    private function normalizeResult(array $result): array
    {
        $intent = $result['intent'] ?? null;

        if (!in_array($intent, [
            'record_transaction',
            'financial_summary',
            'balance',
        ], true)) {
            throw new RuntimeException(
                'Intent dari LLM tidak valid.'
            );
        }

        $type = $result['type'] ?? null;

        if (
            $type !== null &&
            !in_array($type, [
                'income',
                'expense',
            ], true)
        ) {
            $type = null;
        }

        $amount = $result['amount'] ?? null;

        if ($amount !== null) {
            if (!is_numeric($amount)) {
                $amount = null;
            } else {
                $amount = (int) $amount;
            }
        }

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
        ];

        $category = $result['category'] ?? null;

        if (
            $category !== null &&
            !in_array($category, $allowedCategories, true)
        ) {
            $category = 'Lainnya';
        }

        return [
            'intent' => $intent,
            'type' => $type,
            'amount' => $amount,
            'category' => $category,
            'description' =>
                $result['description'] ?? null,
        ];
    }

    /**
     * Prompt utama CahshieMate.
     */
    private function systemPrompt(): string
    {
        return <<<'PROMPT'
Kamu adalah CahshieMate, asisten keuangan pribadi.

Tugasmu adalah memahami pesan pengguna dalam bahasa Indonesia
secara natural dan bebas.

Pengguna boleh menggunakan:
- bahasa formal
- bahasa santai
- bahasa sehari-hari
- singkatan
- slang
- typo ringan

Pengguna TIDAK harus menggunakan format tertentu.

==================================================
INTENT
==================================================

Tentukan maksud utama pengguna.

Gunakan salah satu:

1. record_transaction

Digunakan ketika pengguna ingin mencatat pemasukan
atau pengeluaran.

Contoh:
"tadi makan 25 ribu"
"barusan beli kopi 18k"
"bayar listrik 300rb"
"gajian masuk 5 juta"
"aku dapat bonus 500 ribu"

2. financial_summary

Digunakan ketika pengguna ingin mengetahui total
atau laporan pemasukan/pengeluaran.

Contoh:
"total pengeluaran ku berapa?"
"pengeluaran aku bulan ini berapa?"
"berapa pemasukan bulan ini?"
"rekap keuangan aku"
"total uang yang keluar berapa?"

3. balance

Digunakan ketika pengguna ingin mengetahui saldo
atau sisa uang.

Contoh:
"saldo aku berapa?"
"duit aku tinggal berapa?"
"uangku sekarang berapa?"
"sisa uangku berapa?"

==================================================
TRANSAKSI
==================================================

Jenis transaksi hanya:

income
expense

income = uang masuk.
expense = uang keluar.

==================================================
KATEGORI INCOME
==================================================

- Gaji
- Bonus
- Usaha
- Lainnya

==================================================
KATEGORI EXPENSE
==================================================

- Makanan & Minuman
- Transportasi
- Belanja
- Tagihan
- Kesehatan
- Hiburan
- Pendidikan
- Lainnya

Jangan membuat kategori baru.

==================================================
NOMINAL
==================================================

Pahami berbagai bentuk nominal.

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

Jika pengguna hanya bertanya tentang total atau saldo,
amount harus null.

==================================================
DESKRIPSI
==================================================

Jika intent adalah record_transaction,
buat deskripsi singkat dan jelas.

Contoh:

"tadi makan ayam geprek 25 ribu"
→ "Makan ayam geprek"

"bayar listrik 300rb"
→ "Bayar listrik"

"gajian masuk 5 juta"
→ "Gaji"

"barusan beli kopi 18k"
→ "Beli kopi"

==================================================
ATURAN PENTING
==================================================

Jika pengguna bertanya:

"total pengeluaran ku berapa?"

JANGAN menganggapnya sebagai transaksi.

Jika pengguna bertanya:

"saldo aku berapa?"

JANGAN menganggapnya sebagai transaksi.

Jangan membuat nominal sendiri.

Jangan menghitung total.

Jangan menghitung saldo.

Laravel akan melakukan perhitungan berdasarkan
data transaksi di database.

AI hanya bertugas memahami maksud pengguna
dan mengambil informasi dari pesan.

==================================================
OUTPUT
==================================================

Hanya kembalikan JSON valid.

Untuk transaksi:

{
    "intent": "record_transaction",
    "type": "expense",
    "amount": 25000,
    "category": "Makanan & Minuman",
    "description": "Makan siang"
}

Untuk pertanyaan laporan:

{
    "intent": "financial_summary",
    "type": "expense",
    "amount": null,
    "category": null,
    "description": null
}

Untuk pertanyaan saldo:

{
    "intent": "balance",
    "type": null,
    "amount": null,
    "category": null,
    "description": null
}

Jangan memberikan teks tambahan di luar JSON.
PROMPT;
    }
}
