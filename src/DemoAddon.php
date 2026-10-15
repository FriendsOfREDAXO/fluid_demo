<?php

namespace FriendsOfRedaxo\FluidDemo;

use Override;
use Redaxo\Core\Addon\Addon;

/**
 * AddOn fluid_demo: Demo für fluid – Besucher bearbeiten die Website zum Ausprobieren, gespeichert wird nichts.
 * Ausschalten: Einstellung „Demo einschalten“ (Website → Demo) oder das AddOn deaktivieren bzw. deinstallieren.
 */
final class DemoAddon extends Addon
{
    #[Override]
    public function boot(): void
    {
        Demo::boot();
    }

    #[Override]
    public function uninstall(): void
    {
        // Einstellungen von fluid (Website → Demo) zurücksetzen, damit nach dem Entfernen nichts übrig bleibt
        if (class_exists(\FriendsOfRedaxo\Fluid\FluidAddon::class)) {
            $fluid = \FriendsOfRedaxo\Fluid\FluidAddon::instance();
            $site = (array) $fluid->getConfig('site', []);
            unset($site['demo'], $site['demo_user'], $site['demo_button'], $site['demo_text']);
            $fluid->setConfig('site', $site);
        }
    }

    /** Cache-Kennung einer Datei in assets/ */
    public static function version(string $file): string
    {
        $path = self::instance()->getPath('assets/' . $file);

        return is_file($path) ? substr(md5((string) filemtime($path)), 0, 8) : '0';
    }
}
