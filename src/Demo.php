<?php

namespace FriendsOfRedaxo\FluidDemo;

use FriendsOfRedaxo\Fluid\Api;
use FriendsOfRedaxo\Fluid\ApiError;
use FriendsOfRedaxo\Fluid\BlockModule;
use FriendsOfRedaxo\Fluid\Editing;
use FriendsOfRedaxo\Fluid\FluidAddon;
use FriendsOfRedaxo\Fluid\LayoutBlock;
use FriendsOfRedaxo\Fluid\Search\Search;
use FriendsOfRedaxo\Fluid\Settings;
use Redaxo\Core\Content\Article;
use Redaxo\Core\Content\ArticleCache;
use Redaxo\Core\Content\ArticleContent;
use Redaxo\Core\Content\ArticleRevision;
use Redaxo\Core\Content\ExtensionPoint\ArticleContentUpdated;
use Redaxo\Core\Content\Module;
use Redaxo\Core\Core;
use Redaxo\Core\Database\Sql;
use Redaxo\Core\ExtensionPoint\Extension;
use Redaxo\Core\ExtensionPoint\ExtensionLevel;
use Redaxo\Core\ExtensionPoint\ExtensionPoint;
use Redaxo\Core\Http\Request;
use Redaxo\Core\Security\BackendLogin;
use Redaxo\Core\Security\User;
use Redaxo\Core\Util\Type;
use Throwable;

use function in_array;
use function is_array;
use function is_scalar;
use function strlen;

/**
 * Demo für fluid: Besucher probieren das Bearbeiten auf der Website aus – ohne Anmeldung, ohne dass etwas gespeichert wird.
 *
 * Ablauf:
 *  - Admin schaltet die Demo unter Website → Demo ein und wählt einen Benutzer ohne Admin-Rechte (dessen Rechte gelten).
 *  - Besucher sehen einen Knopf „Demo starten“. Erst nach Klick und Prüfung (Gate) gibt es ein Demo-Cookie – vorher
 *    keine Leiste und keinen Gastzugang.
 *  - Mit Cookie meldet fluid den Besucher als Gast an (FLUID_GUEST_USER). Gäste arbeiten in der Live-Fassung, aber nur in
 *    einer Datenbank-Transaktion: Jede Anfrage bereitet die Seite vor (prepare: Offline-Blöcke und interne Felder weg),
 *    spielt die im Browser gemerkten Aktionen ab (localStorage, assets/demo.js), führt die aktuelle Aktion aus und rollt
 *    alles zurück. Gespeichert wird nie etwas. Neue Blöcke bekommen feste IDs (PIN_BASE + n), damit sie bei jedem
 *    Durchlauf gleich heißen.
 *  - Erlaubt sind nur Block-Aktionen dieser Seite (WRITES, READS); Veröffentlichen zeigt nur einen Hinweis. Alles andere
 *    lehnt fluid für Gäste ohnehin ab (Api: Gäste nur über FLUID_API_ACTION).
 */
final class Demo
{
    public const string POST = 'fluid_demo';
    /** Aktionen, die der Browser sich merkt und bei jeder Anfrage wiederholen lässt */
    public const array WRITES = ['save', 'add', 'move', 'reorder', 'col_move', 'paste', 'duplicate', 'status', 'delete'];
    /** Nur lesende Aktionen (ohne Verlauf, Entwürfe, Aktivität, Seitenbaum – dort stünden Namen der Redaktion und Offline-Seiten) */
    public const array READS = ['form', 'preview', 'render', 'copy', 'design'];   // design: nur Vorschau, Speichern (design_save) bleibt gesperrt
    private const int PIN_BASE = 1_900_000_000;
    private const int MAX_OPS = 60;
    private const int MAX_BYTES = 200_000;

    private static ?bool $active = null;
    private static bool $running = false;
    private static int $seq = 0;
    private static bool $pinned = false;

    /** Demo eingeschaltet (Einstellung und Demo-Benutzer vorhanden) */
    public static function enabled(): bool
    {
        return (bool) Settings::get('demo', false) && null !== self::user();
    }

    /** Diese Anfrage gehört zu einer gestarteten Demo: Website, Demo an, Demo-Cookie gültig, niemand im Backend angemeldet */
    public static function active(): bool
    {
        return self::$active ??= Core::isFrontend() && self::enabled() && Gate::valid() && null === BackendLogin::createUser();
    }

    /** Benutzer, dessen Rechte die Demo verwendet – nie mit Admin-Rechten */
    public static function user(): ?User
    {
        $login = (string) Settings::get('demo_user', '');
        $user = '' === $login ? null : User::forLogin($login);

        return $user && !$user->admin ? $user : null;
    }

    /** Auswahl für die Einstellung „Demo-Benutzer“: aktive Benutzer ohne Admin-Rechte */
    public static function userOptions(): array
    {
        $out = ['' => '– bitte wählen –'];
        foreach (Sql::factory()->getArray('SELECT login, name FROM rex_user WHERE admin = 0 AND status = 1 ORDER BY name, login') as $u) {
            $out[(string) $u['login']] = trim((string) $u['name']) ?: (string) $u['login'];
        }

        return $out;
    }

    public static function boot(): void
    {
        // Einstellungen in fluid (Website → Demo, nur Admins)
        Extension::register('FLUID_SETTINGS_SCHEMA', static function (ExtensionPoint $ep): array {
            return (array) $ep->subject + ['demo' => ['label' => 'Demo', 'fields' => [
                ['name' => 'demo', 'label' => 'Demo einschalten', 'type' => 'bool', 'default' => false,
                    'help' => 'Besucher sehen den Knopf „Demo starten“. Nach einer kurzen, unsichtbaren Prüfung (kein Bot) können sie die Seiten bearbeiten und alles ausprobieren. Gespeichert wird nichts: Die Änderungen bleiben nur im Browser.'],
                ['name' => 'demo_user', 'label' => 'Rechte wie Benutzer', 'type' => 'select', 'options' => self::userOptions(),
                    'help' => 'Welche Blöcke Besucher nutzen dürfen, richtet sich nach diesem Benutzer (nur Benutzer ohne Admin-Rechte). Ohne Auswahl bleibt die Demo aus.'],
                ['name' => 'demo_button', 'label' => 'Beschriftung des Knopfs', 'type' => 'text', 'max' => 40, 'placeholder' => 'Demo starten'],
                ['name' => 'demo_text', 'label' => 'Hinweis in der Leiste (optional)', 'type' => 'text', 'max' => 160, 'placeholder' => 'Probieren Sie alles aus – Ihre Änderungen bleiben nur in diesem Browser.'],
                ['name' => 'demo_banner', 'label' => 'Hinweis „Demo-Website“ für alle Besucher zeigen', 'type' => 'bool', 'default' => false,
                    'help' => 'Ein schmaler Streifen ganz oben auf jeder Seite – z. B. damit niemand die Demo für eine offizielle Website hält.'],
                ['name' => 'demo_banner_text', 'label' => 'Text des Hinweises', 'type' => 'text', 'max' => 200, 'placeholder' => 'Demo-Website für fluid – keine offizielle Website.'],
                ['name' => 'demo_operator', 'label' => 'Betrieben von (Name)', 'type' => 'text', 'max' => 80, 'width' => 'half', 'placeholder' => 'z. B. Agentur Muster'],
                ['name' => 'demo_operator_url', 'label' => 'Betrieben von (Adresse)', 'type' => 'text', 'max' => 200, 'width' => 'half', 'placeholder' => 'https://…'],
            ]]];
        });

        if (!Core::isFrontend()) {
            return;
        }

        // Einlass (?fluid_demo_gate=challenge|start|end) – nach allen AddOns
        if ('' !== ($step = Request::get(Gate::PARAM, 'string', ''))) {
            Extension::register('PACKAGES_INCLUDED', static fn () => Gate::handle($step));

            return;
        }

        // Gastzugang für fluid – nur mit gültigem Demo-Cookie; Gäste arbeiten in der Live-Fassung (immer zurückgerollt)
        Extension::register('FLUID_GUEST_USER', static function (ExtensionPoint $ep): ?User {
            if (!self::active()) {
                return $ep->subject;
            }
            Editing::guestRevision(ArticleRevision::LIVE);

            return self::user();
        });

        // Aktionen des Editors für Gäste übernehmen
        Extension::register('FLUID_API_ACTION', static function (ExtensionPoint $ep): mixed {
            if (!$ep->getParam('guest') || !self::active()) {
                return $ep->subject;
            }
            if (Gate::limited('api')) {
                throw new ApiError('Zu viele Aktionen in kurzer Zeit – bitte einen Moment warten.', 429);
            }
            [$result, $state] = self::run($ep->getParam('article'), $ep->getParam('user'), (string) $ep->getParam('action'), $ep->getParam('run'), $ep->getParam('state'));

            return ['result' => ['demo' => true] + $result, 'state' => $state];
        });

        // Editor: Hinweis-Leiste, Skript, keine Auswahl aus Medienpool/Linkmap
        Extension::register('FLUID_EDITOR_CONFIG', static function (ExtensionPoint $ep): array {
            $cfg = (array) $ep->subject;
            if (!self::active() || !Editing::isGuest()) {
                return $cfg;
            }
            /** @var Article $article */
            $article = $ep->getParam('article');
            $addon = DemoAddon::instance();
            $cfg['notice'] = ['tag' => 'Demo', 'text' => (string) Settings::get('demo_text', '') ?: 'Probieren Sie alles aus – Ihre Änderungen bleiben nur in diesem Browser.',
                'buttons' => [['label' => 'Zurücksetzen', 'event' => 'fluid-demo:reset', 'hidden' => true], ['label' => 'Demo beenden', 'event' => 'fluid-demo:end']]];
            $cfg['demo'] = ['key' => 'fluid-demo:' . $article->id . ':' . $article->languageId, 'gate' => self::gateUrl(), 'maxOps' => self::MAX_OPS];
            $cfg['scripts'] = [...(array) ($cfg['scripts'] ?? []), $addon->getAssetsUrl('demo.js') . '?v=' . DemoAddon::version('demo.js')];
            $cfg['can'] = ['publish' => true, 'version' => true] + (array) ($cfg['can'] ?? []);

            return $cfg;
        });

        // Neue Blöcke während der Demo auf feste IDs setzen
        Extension::register(ArticleContentUpdated::NAME, static function (ArticleContentUpdated $ep): void {
            if (self::$running && 'slice_added' === $ep->action && $ep->slice) {
                Sql::factory()->setQuery('UPDATE rex_article_slice SET id = ? WHERE id = ?', [self::PIN_BASE + ++self::$seq, $ep->slice->id]);
                self::$pinned = true;
            }
        }, ExtensionLevel::Early);

        // Seite für Gäste: im Bearbeiten-Modus bzw. mit gemerkten Änderungen (POST fluid_demo) unter einer Transaktion rendern
        if ('' === Request::get(Api::PARAM, 'string')) {
            Extension::register('ART_INIT', static function (ExtensionPoint $ep): void {
                $article = Article::getCurrent();
                $ops = self::ops();
                if (!self::active() || !$article || self::$running || (!Editing::requested() && !$ops) || !($user = Editing::user()) || !Editing::isGuest()) {
                    return;
                }
                if (Gate::limited('api')) {
                    return;   // zu viele Anfragen: Seite ohne Änderungen
                }
                $content = Type::instanceOf($ep->subject, ArticleContent::class);
                $content->eval = true;   // aus der Datenbank (Transaktion), nicht aus dem Cache
                $content->sliceRevision = ArticleRevision::LIVE;
                Sql::factory()->beginTransaction();
                self::$running = true;
                self::prepare($article);
                self::replay($article, $user, $ops);
                $finish = static function () use ($article): void {
                    if (!self::$running) {
                        return;
                    }
                    self::$running = false;
                    $sql = Sql::factory();
                    if ($sql->inTransaction()) {
                        $sql->rollBack();
                    }
                    self::resetCounter();
                    ArticleCache::delete($article->id, $article->languageId);
                };
                Extension::register('OUTPUT_FILTER', static function (ExtensionPoint $ep) use ($finish): string {
                    $finish();

                    return (string) $ep->subject;
                }, ExtensionLevel::Late);
                register_shutdown_function($finish);
            });
        }

        // Hinweis „Demo-Website“ ganz oben auf jeder Seite (für alle, unabhängig davon, ob die Demo gestartet ist)
        Extension::register('OUTPUT_FILTER', static function (ExtensionPoint $ep): string {
            $html = (string) $ep->subject;
            if (!Settings::get('demo_banner', false) || !preg_match('~<body\b[^>]*>~i', $html, $m, PREG_OFFSET_CAPTURE)) {
                return $html;
            }
            $pos = $m[0][1] + \strlen($m[0][0]);

            return substr($html, 0, $pos) . self::banner() . substr($html, $pos);
        }, ExtensionLevel::Late);

        // Knopf „Demo starten“ für Besucher (Demo an, noch nicht gestartet, niemand angemeldet)
        Extension::register('OUTPUT_FILTER', static function (ExtensionPoint $ep): string {
            $html = (string) $ep->subject;
            if (!self::enabled() || Gate::valid() || null !== BackendLogin::createUser() || !str_contains($html, '</body>')) {
                return $html;
            }
            $addon = DemoAddon::instance();
            $label = (string) Settings::get('demo_button', '') ?: 'Demo starten';
            $tag = '<script src="' . htmlspecialchars($addon->getAssetsUrl('demo-start.js') . '?v=' . DemoAddon::version('demo-start.js')) . '" defer'
                . ' data-gate="' . htmlspecialchars(self::gateUrl()) . '" data-label="' . htmlspecialchars($label) . '"></script>';
            $pos = strripos($html, '</body>');

            return substr($html, 0, $pos) . $tag . substr($html, $pos);
        }, ExtensionLevel::Late);
    }

    /** Streifen „Demo-Website … Betrieben von …“ (eigene Stile, lesbar auf jedem Kit) */
    public static function banner(): string
    {
        $e = static fn (string $v) => htmlspecialchars($v, ENT_QUOTES);
        $text = trim((string) Settings::get('demo_banner_text', '')) ?: 'Demo-Website für fluid – keine offizielle Website.';
        $name = trim((string) Settings::get('demo_operator', ''));
        $url = trim((string) Settings::get('demo_operator_url', ''));
        $by = '' === $name ? '' : ' <span class="fdb__by">Betrieben von ' . (preg_match('~^https?://~', $url) ? '<a href="' . $e($url) . '" rel="noopener">' . $e($name) . '</a>' : $e($name)) . '</span>';

        return '<style>.fdb{position:relative;z-index:50;padding:.5rem 1rem;background:#fff4d6;color:#3d2e00;border-bottom:1px solid #e9cf86;font:500 .875rem/1.45 system-ui,sans-serif;text-align:center}'
            . '.fdb strong{color:inherit}.fdb a{color:#3d2e00;font-weight:700;text-decoration:underline;text-underline-offset:.15em}.fdb a:focus-visible{outline:2px solid #3d2e00;outline-offset:2px}.fdb__by{white-space:nowrap}'
            . '@media (prefers-color-scheme:dark){.fdb{background:#3d2e00;color:#fff4d6;border-color:#6b5310}.fdb a{color:#fff4d6}.fdb a:focus-visible{outline-color:#fff4d6}}'
            . '.fdb__start{display:inline-flex;align-items:center;gap:.4rem;min-height:2rem;margin:.15rem 0 .15rem .75rem;padding:0 .9rem;border:0;border-radius:999px;background:#1d2230;color:#fff;font:700 .875rem/1 system-ui,sans-serif;cursor:pointer;vertical-align:middle}'
            . '.fdb__start:hover{background:#2b3245}.fdb__start:focus-visible{outline:3px solid #8a96ff;outline-offset:2px}.fdb__start[hidden]{display:none}</style>'
            . '<div class="fdb" role="note"><strong>Demo:</strong> ' . $e($text) . $by
            // Knopf zum Start (demo-start.js blendet ihn ein, wenn die Demo startbar ist – ohne JavaScript bleibt er verborgen)
            . '<button type="button" class="fdb__start" data-fluid-demo-start hidden>' . $e((string) Settings::get('demo_button', '') ?: 'Demo starten') . ' →</button></div>';
    }

    private static function gateUrl(): string
    {
        return \FriendsOfRedaxo\Fluid\Routing\Router::base() . '/?' . Gate::PARAM . '=';
    }

    /**
     * Aktion des Editors für Gäste: Seite vorbereiten, gemerkte Änderungen wiederholen, Aktion ausführen, Stand ermitteln,
     * alles zurückrollen.
     *
     * @param callable(): array $action
     * @param callable(): array $state
     * @return array{0: array, 1: array}
     */
    public static function run(Article $article, User $user, string $name, callable $action, callable $state): array
    {
        if ('publish' === $name) {
            return [['message' => 'Demo: Auf einer echten Website wäre die Seite jetzt veröffentlicht. Hier bleibt alles nur in Ihrem Browser.', 'demo' => 'publish'], ['draft' => true, 'workVersion' => true]];
        }
        if (!in_array($name, self::WRITES, true) && !in_array($name, self::READS, true)) {
            throw new ApiError('In der Demo nicht verfügbar.', 403);
        }
        $sql = Sql::factory();
        $sql->beginTransaction();
        self::$running = true;
        try {
            self::prepare($article);
            self::replay($article, $user, self::ops());
            $result = $action();
            $st = $state();
        } finally {
            self::$running = false;
            $sql->rollBack();
            self::resetCounter();
            ArticleCache::delete($article->id, $article->languageId);
        }

        return [$result, $st];
    }

    /**
     * Zähler für neue Block-IDs zurücksetzen. Die festen IDs neuer Blöcke (PIN_BASE + n) heben bei MariaDB/MySQL 8 den
     * AUTO_INCREMENT dauerhaft an – das Zurückrollen nimmt das nicht zurück. Ohne Rücksetzen bekämen danach auch echte Blöcke
     * IDs ab PIN_BASE und die Demo-IDs kollidierten. „= 1“ setzt den Zähler auf die höchste vorhandene ID + 1 (außerhalb der
     * Transaktion, ALTER TABLE beendet sonst die Transaktion).
     */
    private static function resetCounter(): void
    {
        if (!self::$pinned) {
            return;
        }
        self::$pinned = false;
        try {
            Sql::factory()->setQuery('ALTER TABLE rex_article_slice AUTO_INCREMENT = 1');
        } catch (Throwable) {
            // ohne Recht ALTER: Zähler bleibt hoch – neue echte Blöcke liegen dann im Bereich PIN_BASE (siehe README)
        }
    }

    /**
     * Ausgangsstand der Demo (nur innerhalb der Transaktion): Live-Fassung, wie Besucher sie sehen.
     * Offline-Blöcke und Spalten von Offline-Layouts entfernen, interne Felder leeren (Empfänger, Bestätigungstexte,
     * Platzhalter … – Search::NO_FIELDS und Felder mit 'search' => false).
     */
    private static function prepare(Article $article): void
    {
        $sql = Sql::factory();
        $rows = $sql->getArray('SELECT id, module, value1, value2, status, ctype_id FROM rex_article_slice WHERE article_id = ? AND language_id = ? AND revision = ? ORDER BY ctype_id, priority',
            [$article->id, $article->languageId, ArticleRevision::LIVE]);
        $layoutOn = true;
        $ctype = null;
        foreach ($rows as $r) {
            if ($ctype !== $r['ctype_id']) {
                [$ctype, $layoutOn] = [$r['ctype_id'], true];
            }
            $m = Module::get((string) $r['module']);
            $on = 1 === (int) $r['status'];
            $col = (int) (BlockModule::decode((string) $r['value2'])['col'] ?? 0);
            if (0 === $col) {
                $layoutOn = !$m instanceof LayoutBlock || $on;
            }
            if (!$on || ($col > 0 && !$layoutOn)) {
                Sql::factory()->setQuery('DELETE FROM rex_article_slice WHERE id = ?', [(int) $r['id']]);
                continue;
            }
            if ($m instanceof BlockModule) {
                $data = BlockModule::decode((string) $r['value1']);
                $masked = self::mask($m->fields(), $data);
                if ($masked !== $data) {
                    Sql::factory()->setQuery('UPDATE rex_article_slice SET value1 = ? WHERE id = ?', [json_encode($masked, JSON_UNESCAPED_UNICODE), (int) $r['id']]);
                }
            }
        }
    }

    /** Interne Felder leeren (rekursiv in Wiederholgruppen) */
    private static function mask(array $fields, array $data): array
    {
        foreach ($fields as $f) {
            $name = (string) ($f['name'] ?? '');
            if ('' === $name || !array_key_exists($name, $data)) {
                continue;
            }
            if ('repeater' === ($f['type'] ?? '') && is_array($data[$name])) {
                foreach ($data[$name] as $i => $item) {
                    if (is_array($item)) {
                        $data[$name][$i] = self::mask((array) ($f['fields'] ?? []), $item);
                    }
                }
                continue;
            }
            if (false === ($f['search'] ?? true) || in_array($name, Search::NO_FIELDS, true)) {
                $data[$name] = is_array($data[$name]) ? [] : '';
            }
        }

        return $data;
    }

    /**
     * Gemerkte Aktionen aus der Anfrage (geprüft, gekürzt).
     *
     * @return list<array{a: string, p: array}>
     */
    private static function ops(): array
    {
        $raw = (string) ($_POST[self::POST] ?? '');
        if ('' === $raw || strlen($raw) > self::MAX_BYTES) {
            return [];
        }
        $list = json_decode($raw, true);
        if (!is_array($list)) {
            return [];
        }
        $ops = [];
        foreach (array_slice($list, 0, self::MAX_OPS) as $op) {
            if (!is_array($op) || !in_array($op['a'] ?? null, self::WRITES, true) || !is_array($op['p'] ?? null) || !self::scalars($op['p'])) {
                continue;
            }
            $ops[] = ['a' => (string) $op['a'], 'p' => $op['p']];
        }

        return $ops;
    }

    /** Nur Texte, Zahlen und verschachtelte Listen (keine Objekte, keine Dateien) */
    private static function scalars(array $a, int $depth = 0): bool
    {
        if ($depth > 8) {
            return false;
        }
        foreach ($a as $v) {
            if (is_array($v) ? !self::scalars($v, $depth + 1) : !(is_scalar($v) || null === $v)) {
                return false;
            }
        }

        return true;
    }

    /** @param list<array{a: string, p: array}> $ops */
    private static function replay(Article $article, User $user, array $ops): void
    {
        self::$seq = 0;
        $post = $_POST;
        $get = $_GET[Api::PARAM] ?? null;
        try {
            foreach ($ops as $op) {
                $_POST = $op['p'];
                $_GET[Api::PARAM] = $op['a'];
                try {
                    Editing::render(static fn () => Api::dispatch($op['a'], $article, $user));
                } catch (Throwable) {
                    // veraltete Aktion (z. B. Block gibt es nicht mehr): überspringen
                }
            }
        } finally {
            $_POST = $post;
            if (null === $get) {
                unset($_GET[Api::PARAM]);
            } else {
                $_GET[Api::PARAM] = $get;
            }
        }
    }
}
