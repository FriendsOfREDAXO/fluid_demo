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
    public const array READS = ['form', 'preview', 'render', 'copy'];
    private const int PIN_BASE = 1_900_000_000;
    private const int MAX_OPS = 60;
    private const int MAX_BYTES = 200_000;

    private static ?bool $active = null;
    private static bool $running = false;
    private static int $seq = 0;

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
                    ArticleCache::delete($article->id, $article->languageId);
                };
                Extension::register('OUTPUT_FILTER', static function (ExtensionPoint $ep) use ($finish): string {
                    $finish();

                    return (string) $ep->subject;
                }, ExtensionLevel::Late);
                register_shutdown_function($finish);
            });
        }

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
            ArticleCache::delete($article->id, $article->languageId);
        }

        return [$result, $st];
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
