<?php

namespace App\Enums;

/**
 * Productivity classification of an application / URL.
 */
enum ProductivityRating: string
{
    case Productive = 'productive';
    case Unproductive = 'unproductive';
    case Neutral = 'neutral';

    public function label(): string
    {
        return match ($this) {
            self::Productive => 'Productive',
            self::Unproductive => 'Unproductive',
            self::Neutral => 'Neutral',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Productive => 'green',
            self::Unproductive => 'red',
            self::Neutral => 'slate',
        };
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
