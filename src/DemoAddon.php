<?php

namespace FriendsOfRedaxo\FluidDemo;

use Override;
use Redaxo\Core\Addon\Addon;

/**
 * AddOn fluid_demo: Demo für fluid – Besucher bearbeiten die Website zum Ausprobieren, gespeichert wird nichts.
 * Ausschalten: Einstellung „Demo einschalten“ (Website → Demo). Entfernen: Demo ausschalten, dann per Composer entfernen.
 */
final class DemoAddon extends Addon
{
    #[Override]
    public function boot(): void
    {
        Demo::boot();
    }

    /** Cache-Kennung einer Datei in assets/ */
    public static function version(string $file): string
    {
        $path = self::instance()->getPath('assets/' . $file);

        return is_file($path) ? substr(md5((string) filemtime($path)), 0, 8) : '0';
    }
}
