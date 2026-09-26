<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('budgets', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnDelete();

            $table->foreignId('category_id')
                ->constrained('categories')
                ->cascadeOnDelete();

            $table->unsignedBigInteger('limit_amount');

            $table->unsignedTinyInteger('month');
            $table->unsignedSmallInteger('year');

            $table->timestamps();

            $table->unique([
                'user_id',
                'category_id',
                'month',
                'year'
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('budgets');
    }
};
