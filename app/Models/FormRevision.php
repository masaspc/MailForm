<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FormRevision extends Model
{
    protected $fillable = ['form_id', 'revision', 'definition'];

    protected function casts(): array
    {
        return [
            'revision' => 'integer',
            'definition' => 'array',
        ];
    }

    public function form(): BelongsTo
    {
        return $this->belongsTo(Form::class);
    }
}
