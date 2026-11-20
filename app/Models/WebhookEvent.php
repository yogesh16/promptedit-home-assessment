<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WebhookEvent extends Model
{
    use HasFactory;

    public const string STATUS_RECEIVED = 'received';

    public const string STATUS_PROCESSED = 'processed';

    public const string STATUS_IGNORED = 'ignored';

    public const string STATUS_FAILED = 'failed';

    protected $fillable = [
        'provider_event_id',
        'event_type',
        'payload',
        'status',
        'attempts',
        'error',
        'processed_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'attempts' => 'integer',
            'processed_at' => 'datetime',
        ];
    }
}
