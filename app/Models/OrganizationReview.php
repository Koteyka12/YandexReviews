<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrganizationReview extends Model
{
    use HasFactory;

    protected $fillable = [
        'organization_id',
        'yandex_review_id',
        'author',
        'reviewed_at',
        'text',
        'rating',
        'raw_payload',
    ];

    protected function casts(): array
    {
        return [
            'reviewed_at' => 'datetime',
            'rating' => 'integer',
            'raw_payload' => 'array',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
