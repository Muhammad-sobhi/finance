<?php

namespace App\Services;

use App\Enums\CategoryType;
use App\Models\User;

class DefaultCategories
{
    /** key => [type, icon, color]; names are translated in the UI via `key`. */
    public const DEFAULTS = [
        'food' => ['expense', 'food', '#c0623b', 'Food'],
        'transport' => ['expense', 'car', '#1e3a5f', 'Transport'],
        'bills' => ['expense', 'bill', '#9b2c2c', 'Bills'],
        'shopping' => ['expense', 'bag', '#b08d57', 'Shopping'],
        'health' => ['expense', 'health', '#2e5e3e', 'Health'],
        'education' => ['expense', 'book', '#4a6fa5', 'Education'],
        'entertainment' => ['expense', 'film', '#8a5a44', 'Entertainment'],
        'family' => ['expense', 'family', '#6b8f71', 'Family'],
        'work' => ['expense', 'work', '#555555', 'Work'],
        'zakat' => ['expense', 'gift', '#2f7d4a', 'Zakat & charity'],
        'other' => ['expense', 'tag', '#6b6a5c', 'Other'],
        'salary' => ['income', 'wallet', '#2e5e3e', 'Salary'],
        'freelance' => ['income', 'work', '#1e3a5f', 'Freelance'],
        'other_income' => ['income', 'tag', '#6b6a5c', 'Other income'],
    ];

    /** Find (or create, for owners seeded before it existed) a default category by key. */
    public static function ensure(User $owner, string $key): int
    {
        $id = $owner->categories()->where('key', $key)->value('id');
        if ($id) {
            return $id;
        }
        [$type, $icon, $color, $name] = self::DEFAULTS[$key];

        return $owner->categories()->create(['key' => $key, 'name' => $name, 'type' => CategoryType::from($type), 'icon' => $icon, 'color' => $color, 'sort' => 99])->id;
    }

    public static function seedFor(User $user): void
    {
        $sort = 0;
        foreach (self::DEFAULTS as $key => [$type, $icon, $color, $name]) {
            $user->categories()->create([
                'key' => $key,
                'name' => $name,
                'type' => CategoryType::from($type),
                'icon' => $icon,
                'color' => $color,
                'sort' => $sort++,
            ]);
        }
    }
}
