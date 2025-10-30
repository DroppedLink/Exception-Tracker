<?php

namespace ETracker;

use function constant;
use function defined;
use function get_option;
use function is_array;

class Settings
{
    public const OPTION_NAME = 'etracker_settings';

    public static function get(string $key, ?string $default = null): string
    {
        $constant = self::constant_name($key);
        if ($constant !== '' && defined($constant) && constant($constant) !== '') {
            return (string) constant($constant);
        }

        $stored = get_option(self::OPTION_NAME, []);
        if (is_array($stored) && isset($stored[$key]) && $stored[$key] !== '') {
            return (string) $stored[$key];
        }

        return $default ?? '';
    }

    public static function all(): array
    {
        return [
            'mongo_uri' => self::get('mongo_uri'),
            'mongo_database' => self::get('mongo_database'),
            'mongo_collection' => self::get('mongo_collection'),
            'default_exception_duration_days' => self::get('default_exception_duration_days', '365'),
        ];
    }

    public static function is_locked(string $key): bool
    {
        $constant = self::constant_name($key);

        return $constant !== '' && defined($constant) && constant($constant) !== '';
    }

    public static function constant_name(string $key): string
    {
        return match ($key) {
            'mongo_uri' => 'ETRACKER_MONGO_URI',
            'mongo_database' => 'ETRACKER_MONGO_DATABASE',
            'mongo_collection' => 'ETRACKER_MONGO_COLLECTION',
            'default_exception_duration_days' => 'ETRACKER_DEFAULT_EXCEPTION_DURATION_DAYS',
            default => '',
        };
    }
}

