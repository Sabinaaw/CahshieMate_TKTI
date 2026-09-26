<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnDelete();

            $table->foreignId('category_id')
                ->nullable()
                ->constrained('categories')
                ->nullOnDelete();

            $table->enum('type', ['income', 'expense']);

            $table->unsignedBigInteger('amount');

            $table->string('description');

            $table->date('transaction_date');

            $table->enum('source', [
                'text',
                'receipt',
                'manual'
            ])->default('text');

            $table->string('receipt_path')->nullable();

            $table->timestamps();

            $table->index([
                'user_id',
                'transaction_date'
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
