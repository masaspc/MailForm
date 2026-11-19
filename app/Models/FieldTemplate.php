<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FieldTemplate extends Model
{
    protected $fillable = ['definition', 'legacy_id'];

    protected function casts(): array
    {
        return ['definition' => 'array'];
    }
}
