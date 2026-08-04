<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Model;

class Keyword extends Model
{
    protected $fillable = [
        'phrase',
        'enabled',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
        ];
    }

    public function hits(): HasMany
    {
        return $this->hasMany(ArticleKeywordHit::class);
    }
}
