<?php

namespace App\Models;

use App\Helpers\TvaHelper;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Income extends Model
{
    public const SOURCE_AIRBNB  = 'airbnb';
    public const SOURCE_BOOKING = 'booking';
    public const SOURCE_ABRITEL = 'abritel';
    public const SOURCE_DIRECT  = 'direct';
    public const SOURCE_OTHER   = 'other';

    protected $fillable = [
        'property_id',
        'income_date',
        'amount',
        'tva_rate',
        'amount_ht',
        'tva_collected',
        'platform_fee',
        'tourist_tax',
        'source',
        'reservation_ref',
        'guest_name',
        'checkin_date',
        'checkout_date',
        'notes',
    ];

    protected static function booted(): void
    {
        static::saving(function (Income $income) {
            $result = TvaHelper::fromTtc((int) $income->amount, (int) $income->tva_rate);
            $income->amount_ht = $result['ht'];
            $income->tva_collected = $result['tva'];
        });
    }

    protected function casts(): array
    {
        return [
            'income_date'   => 'date',
            'checkin_date'  => 'date',
            'checkout_date' => 'date',
        ];
    }

    public static function sourceLabels(): array
    {
        return [
            self::SOURCE_AIRBNB  => 'Airbnb',
            self::SOURCE_BOOKING => 'Booking.com',
            self::SOURCE_ABRITEL => 'Abritel',
            self::SOURCE_DIRECT  => 'Direct',
            self::SOURCE_OTHER   => 'Autre',
        ];
    }

    public function getNetAmountAttribute(): string
    {
        return bcsub((string) $this->amount, (string) $this->platform_fee, 0);
    }

    public function getAmountEurosAttribute(): string
    {
        return bcdiv((string) $this->amount, '100', 2);
    }

    public function getAmountHtEurosAttribute(): string
    {
        return bcdiv((string) $this->amount_ht, '100', 2);
    }

    public function getTvaCollectedEurosAttribute(): string
    {
        return bcdiv((string) $this->tva_collected, '100', 2);
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }
}
