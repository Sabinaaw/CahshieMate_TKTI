<?php

namespace App\Http\Webhooks;

use App\Services\Finance\TransactionService;
use DefStudio\Telegraph\Handlers\WebhookHandler;
use Illuminate\Support\Facades\DB;
use Throwable;

class CahshieMateWebhookHandler extends WebhookHandler
{
    public function start(): void
    {
        $this->chat
            ->html(
                "👋 <b>Halo! Saya CahshieMate</b>\n\n" .
                "Saya siap membantu mencatat dan mengelola keuanganmu 💰\n\n" .
                "<b>Contoh penggunaan:</b>\n" .
                "• Tadi makan siang 25 ribu\n" .
                "• Beli kopi 18k\n" .
                "• Gaji masuk 4 juta\n" .
                "• Bayar listrik 300 ribu\n\n" .
                "<b>Cek keuangan:</b>\n" .
                "• Berapa pengeluaran saya bulan ini?\n" .
                "• Pengeluaran buat hiburan berapa?\n" .
                "• Bulan ini uangku habis buat apa aja?\n" .
                "• Berapa pemasukan saya bulan ini?\n" .
                "• Saldo saya berapa?\n\n" .
                "Kirim pesan dan saya akan membantu mengelolanya 🤖"
            )
            ->send();
    }

    public function handleMessage(): void
    {
        try {
            $telegramId = (string) $this->chat->chat_id;

            $text = trim(
                (string) ($this->message->text() ?? '')
            );

            if ($text === '') {
                return;
            }

            if ($text === '/start') {
                $this->start();
                return;
            }

            $service = app(TransactionService::class);

            $result = $service->processTelegramMessage(
                $telegramId,
                $text
            );

            $intent = $result['intent'] ?? null;

            if ($intent === 'record_transaction') {
                $this->handleRecordTransaction($result);
                return;
            }

            if ($intent === 'financial_summary') {
                $this->handleFinancialSummary($result);
                return;
            }

            if ($intent === 'category_summary') {
                $this->handleCategorySummary($result);
                return;
            }

            if ($intent === 'balance') {
                $this->handleBalance($result);
                return;
            }

            if ($intent === 'reset_transactions') {
                $this->handleResetTransactions($result);
                return;
            }

            $this->chat
                ->html(
                    "🤔 <b>Saya belum memahami pesan tersebut.</b>\n\n" .
                    "<b>Kamu bisa mencoba:</b>\n" .
                    "• Tadi makan 25 ribu\n" .
                    "• Gaji masuk 4 juta\n" .
                    "• Berapa pengeluaran saya bulan ini?\n" .
                    "• Pengeluaran buat hiburan berapa?\n" .
                    "• Bulan ini uangku habis buat apa aja?\n" .
                    "• Saldo saya berapa?"
                )
                ->send();
        } catch (Throwable $e) {
            report($e);

            $this->logFailure(
                $this->message->text() ?? '[Pesan kosong]',
                $e
            );

            $this->chat
                ->html(
                    "❌ <b>Terjadi error</b>\n\n" .
                    "<code>" .
                    e($e->getMessage()) .
                    "</code>"
                )
                ->send();
        }
    }

    private function handleRecordTransaction(array $result): void
    {
        $type = $result['type'] ?? 'expense';
        $amount = $result['amount'] ?? null;

        if ($amount === null || $amount <= 0) {
            $category = e(
                $result['category'] ?? 'Lainnya'
            );

            $typeText = $type === 'income'
                ? 'pemasukan'
                : 'pengeluaran';

            $this->chat
                ->html(
                    "💬 <b>Oke!</b>\n\n" .
                    "Kamu ingin mencatat {$typeText}" .
                    " untuk kategori <b>{$category}</b>.\n\n" .
                    "💵 Nominalnya berapa?"
                )
                ->send();

            return;
        }

        $typeLabel = $type === 'income'
            ? 'Pemasukan'
            : 'Pengeluaran';

        $emoji = $type === 'income'
            ? '💰'
            : '💸';

        $formattedAmount = number_format(
            $amount,
            0,
            ',',
            '.'
        );

        $category = e(
            $result['category'] ?? 'Lainnya'
        );

        $description = e(
            $result['description'] ?? '-'
        );

        $this->chat
            ->html(
                "✅ <b>Transaksi berhasil dicatat!</b>\n\n" .
                "{$emoji} <b>{$typeLabel}</b>\n" .
                "💵 Jumlah: <b>Rp{$formattedAmount}</b>\n" .
                "📂 Kategori: {$category}\n" .
                "📝 Deskripsi: {$description}\n\n" .
                "📅 " . now()->format('d/m/Y')
            )
            ->send();
    }

    private function handleFinancialSummary(array $result): void
    {
        $periodLabel = e(
            $this->getPeriodLabel($result)
        );

        $breakdown = (bool) (
            $result['breakdown'] ?? false
        );

        if ($breakdown) {
            $this->sendBreakdownSummary(
                $result,
                $periodLabel
            );

            return;
        }

        $income = number_format(
            $result['income'] ?? 0,
            0,
            ',',
            '.'
        );

        $expense = number_format(
            $result['expense'] ?? 0,
            0,
            ',',
            '.'
        );

        $balance = number_format(
            $result['balance'] ?? 0,
            0,
            ',',
            '.'
        );

        $this->chat
            ->html(
                "📊 <b>Ringkasan Keuangan</b>\n" .
                "📅 {$periodLabel}\n\n" .
                "💰 Total Pemasukan: <b>Rp{$income}</b>\n" .
                "💸 Total Pengeluaran: <b>Rp{$expense}</b>\n\n" .
                "💵 <b>Uang Tersisa: Rp{$balance}</b>"
            )
            ->send();
    }

    private function handleCategorySummary(array $result): void
    {
        $category = $result['category'] ?? null;

        if (!$category) {
            $this->chat
                ->html(
                    "🤔 <b>Kategori pengeluaran belum diketahui.</b>\n\n" .
                    "Coba tanyakan seperti:\n" .
                    "• Pengeluaran buat hiburan berapa?\n" .
                    "• Aku habis berapa buat makan?\n" .
                    "• Total belanja bulan ini berapa?"
                )
                ->send();

            return;
        }

        $amount = number_format(
            $result['expense'] ?? $result['total'] ?? 0,
            0,
            ',',
            '.'
        );

        $periodLabel = e(
            $this->getPeriodLabel($result)
        );

        $categoryLabel = e($category);

        $this->chat
            ->html(
                "📂 <b>Pengeluaran {$categoryLabel}</b>\n" .
                "📅 {$periodLabel}\n\n" .
                "💸 Total: <b>Rp{$amount}</b>"
            )
            ->send();
    }

    private function handleBalance(array $result): void
    {
        $income = number_format(
            $result['income'] ?? 0,
            0,
            ',',
            '.'
        );

        $expense = number_format(
            $result['expense'] ?? 0,
            0,
            ',',
            '.'
        );

        $balance = number_format(
            $result['balance'] ?? 0,
            0,
            ',',
            '.'
        );

        $periodLabel = e(
            $this->getPeriodLabel($result)
        );

        $this->chat
            ->html(
                "💵 <b>Saldo Keuangan</b>\n" .
                "📅 {$periodLabel}\n\n" .
                "💰 Total Pemasukan: <b>Rp{$income}</b>\n" .
                "💸 Total Pengeluaran: <b>Rp{$expense}</b>\n\n" .
                "💵 <b>Uang Tersisa: Rp{$balance}</b>"
            )
            ->send();
    }

    private function handleResetTransactions(array $result): void
    {
        $deleted = (int) ($result['deleted'] ?? 0);

        $periodLabel = e(
            $this->getPeriodLabel($result)
        );

        $this->chat
            ->html(
                "🗑️ <b>Reset Keuangan Berhasil</b>\n" .
                "📅 {$periodLabel}\n\n" .
                "✅ {$deleted} transaksi berhasil dihapus."
            )
            ->send();
    }

    private function sendBreakdownSummary(
        array $result,
        string $periodLabel
    ): void {
        $breakdown = $result['breakdown_data'] ?? [];

        if (empty($breakdown)) {
            $this->chat
                ->html(
                    "📊 <b>Ringkasan Pengeluaran</b>\n" .
                    "📅 {$periodLabel}\n\n" .
                    "✨ Belum ada pengeluaran pada periode tersebut."
                )
                ->send();

            return;
        }

        $message =
            "📊 <b>Ringkasan Pengeluaran</b>\n" .
            "📅 {$periodLabel}\n\n";

        $total = 0;

        foreach ($breakdown as $item) {
            $category = e(
                $item['category'] ?? 'Lainnya'
            );

            $amount = (int) (
                $item['amount'] ?? 0
            );

            $total += $amount;

            $emoji = $this->getCategoryEmoji(
                $item['category'] ?? 'Lainnya'
            );

            $formattedAmount = number_format(
                $amount,
                0,
                ',',
                '.'
            );

            $message .=
                "{$emoji} <b>{$category}</b>\n" .
                "Rp{$formattedAmount}\n\n";
        }

        $formattedTotal = number_format(
            $total,
            0,
            ',',
            '.'
        );

        $message .=
            "━━━━━━━━━━━━━━━━\n" .
            "💸 <b>Total</b>\n" .
            "<b>Rp{$formattedTotal}</b>";

        $this->chat
            ->html($message)
            ->send();
    }

    private function getCategoryEmoji(string $category): string
    {
        return match ($category) {
            'Makanan & Minuman' => '🍔',
            'Fashion' => '👕',
            'Transportasi' => '🚗',
            'Belanja' => '🛍️',
            'Tagihan' => '💡',
            'Kesehatan' => '🏥',
            'Hiburan' => '🎮',
            'Pendidikan' => '📚',
            'Gaji' => '💼',
            'Bonus' => '🎁',
            'Usaha' => '💻',
            default => '📌',
        };
    }

    private function getPeriodLabel(array $result): string
    {
        $period = $result['period'] ?? 'this_month';

        $startDate = $result['start_date'] ?? null;
        $endDate = $result['end_date'] ?? null;

        if (
            $period === 'custom' &&
            $startDate &&
            $endDate
        ) {
            return date('d/m/Y', strtotime($startDate)) .
                ' - ' .
                date('d/m/Y', strtotime($endDate));
        }

        return match ($period) {
            'today' => 'Hari Ini',
            'yesterday' => 'Kemarin',
            'this_week' => 'Minggu Ini',
            'last_week' => 'Minggu Lalu',
            'this_month' => now()->translatedFormat('F Y'),
            'last_month' => now()
                ->copy()
                ->subMonth()
                ->translatedFormat('F Y'),
            'this_year' => 'Tahun ' . now()->year,
            'last_30_days' => '30 Hari Terakhir',
            'all_time' => 'Semua Waktu',
            default => 'Periode yang Dipilih',
        };
    }

    private function logFailure(
        string $input,
        Throwable $e
    ): void {
        try {
            $telegramId = (string) $this->chat->chat_id;

            $user = DB::table('users')
                ->where('telegram_id', $telegramId)
                ->first();

            DB::table('ai_interactions')->insert([
                'user_id' => $user?->id,
                'input_text' => $input,
                'ai_response' => null,
                'status' => 'failed',
                'error_message' => $e->getMessage(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (Throwable $logError) {
            report($logError);
        }
    }
}
