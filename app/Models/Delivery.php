<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Delivery extends Model
{
    protected $fillable = ['submission_id', 'kind', 'status', 'payload', 'attempts', 'last_error'];

    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'payload' => 'encrypted:array',
        ];
    }

    public function submission(): BelongsTo
    {
        return $this->belongsTo(Submission::class);
    }
}
