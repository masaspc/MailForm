<?php

namespace App\Models;

use App\Services\PublicFormUrl;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Form extends Model
{
    protected $fillable = ['legacy_id', 'slug', 'name', 'published', 'revision', 'definition', 'draft_definition'];

    protected function casts(): array
    {
        return [
            'published' => 'boolean',
            'revision' => 'integer',
            'definition' => 'array',
            'draft_definition' => 'array',
        ];
    }

    public function publicPath(string $action = ''): string
    {
        return PublicFormUrl::form($this->slug, $action);
    }

    public function submissions(): HasMany
    {
        return $this->hasMany(Submission::class);
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(FormRevision::class);
    }
}
