<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiInteraction extends Model
{
    protected $fillable = [
        'user_id',
        'input_text',
        'ai_response',
        'status',
        'error_message',
    ];

    protected function casts(): array
    {
        return [
            'ai_response' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
