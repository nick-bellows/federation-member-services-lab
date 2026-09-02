<?php

namespace App\Federation\Models;

use Database\Factories\Federation\SeasonFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Season extends Model
{
    use HasFactory;

    protected $fillable = [
        'federation_id',
        'label',
        'starts_on',
        'ends_on',
    ];

    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
        ];
    }

    public function federation(): BelongsTo
    {
        return $this->belongsTo(Federation::class);
    }

    public function applications(): HasMany
    {
        return $this->hasMany(RegistrationApplication::class);
    }

    protected static function newFactory(): SeasonFactory
    {
        return SeasonFactory::new();
    }
}
