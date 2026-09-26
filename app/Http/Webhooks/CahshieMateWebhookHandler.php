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

            if (($result['intent'] ?? null) === 'record_transaction') {
                $typeLabel = $result['type'] === 'income'
                    ? 'Pemasukan'
                    : 'Pengeluaran';

                $emoji = $result['type'] === 'income'
                    ? '💰'
                    : '💸';

                $amount = number_format(
                    $result['amount'] ?? 0,
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
                        "💵 Jumlah: <b>Rp{$amount}</b>\n" .
                        "📂 Kategori: {$category}\n" .
                        "📝 Deskripsi: {$description}\n\n" .
                        "📅 " . now()->format('d/m/Y')
                    )
                    ->send();

                return;
            }

            if (($result['intent'] ?? null) === 'financial_summary') {
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
                        "📊 <b>Ringkasan Keuangan {$periodLabel}</b>\n\n" .
                        "💰 Total Pemasukan: <b>Rp{$income}</b>\n" .
                        "💸 Total Pengeluaran: <b>Rp{$expense}</b>\n\n" .
                        "💵 <b>Uang Tersisa: Rp{$balance}</b>"
                    )
                    ->send();

                return;
            }

            if (($result['intent'] ?? null) === 'balance') {
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
                        "💵 <b>Saldo Keuangan {$periodLabel}</b>\n\n" .
                        "💰 Total Pemasukan: <b>Rp{$income}</b>\n" .
                        "💸 Total Pengeluaran: <b>Rp{$expense}</b>\n\n" .
                        "💵 <b>Uang Tersisa: Rp{$balance}</b>"
                    )
                    ->send();

                return;
            }

            if (($result['intent'] ?? null) === 'reset_transactions') {
                $deleted = (int) ($result['deleted'] ?? 0);
                $periodLabel = e(
                    $this->getPeriodLabel($result)
                );

                if ($deleted > 0) {
                    $transactionText = $deleted === 1
                        ? '1 transaksi'
                        : "{$deleted} transaksi";

                    $this->chat
                        ->html(
                            "🗑️ <b>Transaksi berhasil direset!</b>\n\n" .
                            "📅 Periode: <b>{$periodLabel}</b>\n" .
                            "Sebanyak <b>{$transactionText}</b> telah dihapus dari catatan keuanganmu. 💰\n\n" .
                            "✨ Catatan transaksi pada periode tersebut sudah bersih."
                        )
                        ->send();

                    return;
                }

                $this->chat
                    ->html(
                        "✨ <b>Tidak ada transaksi yang perlu direset.</b>\n\n" .
                        "📅 Periode: <b>{$periodLabel}</b>\n" .
                        "Tidak ditemukan transaksi pada periode yang kamu minta."
                    )
                    ->send();

                return;
            }

            $this->chat
                ->html(
                    "🤔 <b>Saya belum memahami pesan tersebut.</b>\n\n" .
                    "<b>Kamu bisa mencoba:</b>\n" .
                    "• Tadi makan 25 ribu\n" .
                    "• Gaji masuk 4 juta\n" .
                    "• Berapa pengeluaran saya bulan ini?\n" .
                    "• Berapa pemasukan saya bulan ini?\n" .
                    "• Saldo saya berapa?\n" .
                    "• Reset transaksi"
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

    private function getPeriodLabel(array $result): string
    {
        $periodType = $result['period_type'] ?? 'current';
        $periodUnit = $result['period_unit'] ?? 'month';
        $periodValue = max(
            1,
            (int) ($result['period_value'] ?? 1)
        );

        $specificMonth = (int) (
            $result['specific_month'] ?? 0
        );

        $specificYear = (int) (
            $result['specific_year'] ?? 0
        );

        $months = [
            1 => 'Januari',
            2 => 'Februari',
            3 => 'Maret',
            4 => 'April',
            5 => 'Mei',
            6 => 'Juni',
            7 => 'Juli',
            8 => 'Agustus',
            9 => 'September',
            10 => 'Oktober',
            11 => 'November',
            12 => 'Desember',
        ];

        if ($periodType === 'specific') {
            if (
                $specificMonth >= 1 &&
                $specificMonth <= 12
            ) {
                $year = $specificYear >= 2000
                    ? $specificYear
                    : now()->year;

                return $months[$specificMonth] . ' ' . $year;
            }

            $year = $specificYear >= 2000
                ? $specificYear
                : now()->year;

            return 'Tahun ' . $year;
        }

        if ($periodType === 'relative') {
            if ($periodUnit === 'day') {
                if ($periodValue === 1) {
                    return 'Kemarin';
                }

                return $periodValue . ' Hari Lalu';
            }

            if ($periodUnit === 'year') {
                return 'Tahun ' .
                    (now()->year - $periodValue);
            }

            $date = now()->subMonths($periodValue);

            return $months[$date->month] . ' ' . $date->year;
        }

        if ($periodType === 'current') {
            if ($periodUnit === 'day') {
                return 'Hari Ini';
            }

            if ($periodUnit === 'year') {
                return 'Tahun ' . now()->year;
            }

            return $months[now()->month] . ' ' . now()->year;
        }

        if ($periodType === 'all_time') {
            return 'Semua Waktu';
        }

        return 'Periode yang Dipilih';
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
