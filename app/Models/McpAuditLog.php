<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class McpAuditLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'token_name',
        'tool_name',
        'parameters',
        'result_status',
        'ip_address',
        'duration_ms',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'parameters' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
