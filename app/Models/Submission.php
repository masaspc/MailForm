<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Submission extends Model
{
    protected $fillable = ['form_id', 'legacy_id', 'token', 'retain', 'purged_at', 'answers', 'snapshot', 'created_at', 'updated_at'];

    protected function casts(): array
    {
        return [
            'retain' => 'boolean',
            'purged_at' => 'datetime',
            'answers' => 'encrypted:array',
            'snapshot' => 'encrypted:array',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $submission) {
            if (isset($submission->snapshot['retain'])) {
                $submission->retain = (bool) $submission->snapshot['retain'];
            }
        });
    }

    public function form(): BelongsTo
    {
        return $this->belongsTo(Form::class);
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(Attachment::class);
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(Delivery::class);
    }
}
