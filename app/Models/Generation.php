<?php

namespace App\Models;

use Database\Factories\GenerationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Generation extends Model
{
    /** @use HasFactory<GenerationFactory> */
    use HasFactory;

    public const string STATUS_QUEUED = 'queued';

    public const string STATUS_PROCESSING = 'processing';

    public const string STATUS_SUCCEEDED = 'succeeded';

    public const string STATUS_FAILED = 'failed';

    /** @var list<string> */
    public const array STATUSES = [
        self::STATUS_QUEUED,
        self::STATUS_PROCESSING,
        self::STATUS_SUCCEEDED,
        self::STATUS_FAILED,
    ];

    /** @var list<string> */
    public const array SIZES = ['1024x1024', '1536x1024', '1024x1536'];

    /** @var list<string> */
    public const array QUALITIES = ['low', 'medium', 'high'];

    protected $fillable = [
        'user_id',
        'prompt',
        'size',
        'quality',
        'reference_images',
        'status',
        'idempotency_key',
        'provider_generation_id',
        'rates',
        'estimated_cost_min',
        'estimated_cost_max',
        'usage',
        'provider_cost',
        'customer_charge',
        'image_path',
        'image_url_expires_at',
        'error_code',
        'error_message',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'reference_images' => 'array',
            'rates' => 'array',
            'usage' => 'array',
            'estimated_cost_min' => 'decimal:9',
            'estimated_cost_max' => 'decimal:9',
            'provider_cost' => 'decimal:9',
            'customer_charge' => 'decimal:9',
            'image_url_expires_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, [self::STATUS_SUCCEEDED, self::STATUS_FAILED], true);
    }
}
