<?php

namespace App\Support;

/** Tiny dot-path accessor over config/app.php: Config::get('liveness.min_frames'). */
final class Config
{
    private static ?array $cache = null;

    public static function all(): array
    {
        return self::$cache ??= require __DIR__ . '/../../config/app.php';
    }

    public static function get(string $path, mixed $default = null): mixed
    {
        $node = self::all();
        foreach (explode('.', $path) as $segment) {
            if (!is_array($node) || !array_key_exists($segment, $node)) {
                return $default;
            }
            $node = $node[$segment];
        }
        return $node;
    }

    public static function isProduction(): bool
    {
        return (bool) self::get('is_production', false);
    }

    /** Test helper - forces config to be re-read after putenv() changes. */
    public static function reset(): void
    {
        self::$cache = null;
    }
}
