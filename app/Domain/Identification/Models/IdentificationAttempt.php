<?php

namespace App\Domain\Identification\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One provider call (pass 1, pass 2, retry or fallback). Source of truth for AI cost. */
class IdentificationAttempt extends Model
{
    protected $fillable = [
        'identification_request_id', 'pass', 'provider', 'model', 'ai_tier', 'prompt_version', 'status', 'http_status',
        'input_tokens', 'output_tokens', 'cost_usd_micros', 'latency_ms', 'response_json', 'error_message',
    ];

    protected function casts(): array
    {
        return ['response_json' => 'array'];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(IdentificationRequest::class, 'identification_request_id');
    }
}
