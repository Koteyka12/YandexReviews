<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ParsingJob extends Model
{
    use HasFactory;

    protected $fillable = [
        'url',
        'user_id',
        'organization_id',
        'status',
        'error',
        'scraped_reviews_count',
        'has_new_reviews',
        'previous_reviews_count',
        'new_reviews_count',
        'started_at',
        'completed_at',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
