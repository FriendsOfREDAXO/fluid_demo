<?php

namespace FriendsOfRedaxo\FluidDemo;

use Redaxo\Core\Filesystem\Path;

/**
 * Einlass in die Demo: erst nach Klick auf „Demo starten“ und bestandener Prüfung, dass kein Bot klickt.
 *
 * Prüfung ohne Dienste Dritter und ohne Cookies Dritter (Prinzip wie ALTCHA/Friendly Captcha):
 *   1. challenge(): Aufgabe vom Server – Zufallswert, Schwierigkeit, Ablauf, signiert (HMAC).
 *   2. Der Browser sucht eine Zahl, bei der SHA-256(Zufallswert + Zahl) mit DIFFICULTY Null-Bits beginnt (≈ 1–3 s Rechenzeit, auf Handys etwas länger).
 *      Für Menschen unsichtbar, für Bots je Versuch teuer.
 *   3. start(): Lösung prüfen, dazu Honigtopf-Feld (muss leer sein), Mindestzeit seit dem Öffnen, jede Aufgabe nur einmal,
 *      Begrenzung je IP. Danach ein signiertes Demo-Cookie (HttpOnly, SameSite=Lax), gültig SESSION Sekunden.
 * Ohne gültiges Cookie gibt es keine Demo: keine Leiste, kein Gastzugang, keine Demo-Aktionen.
 */
final class Gate
{
    public const string PARAM = 'fluid_demo_gate';
    public const string COOKIE = 'fluid_demo';
    public const int DIFFICULTY = 21;
    public const int SESSION = 7200;
    private const int CHALLENGE_TTL = 300;
    private const int MIN_MS = 1200;

    /** Begrenzungen je IP: [Anzahl, Sekunden] */
    public const array LIMITS = [
        'challenge' => [30, 3600],
        'start' => [10, 3600],
        'api' => [120, 300],
    ];

    private static ?bool $valid = null;

    private static function key(): string
    {
        $addon = DemoAddon::instance();
        $k = (string) $addon->getConfig('key', '');
        if ('' === $k) {
            $k = bin2hex(random_bytes(32));
            $addon->setConfig('key', $k);
        }

        return $k;
    }

    private static function sign(string $payload): string
    {
        return hash_hmac('sha256', $payload, self::key());
    }

    // ------------------------------------------------------------------ Demo-Cookie

    /** Hat diese Anfrage ein gültiges Demo-Cookie? */
    public static function valid(): bool
    {
        if (null !== self::$valid) {
            return self::$valid;
        }
        $raw = (string) ($_COOKIE[self::COOKIE] ?? '');
        if (!preg_match('~^(\d{10})\.([a-f0-9]{16})\.([a-f0-9]{64})$~', $raw, $m)) {
            return self::$valid = false;
        }

        return self::$valid = (int) $m[1] > time() && hash_equals(self::sign('session|' . $m[1] . '|' . $m[2]), $m[3]);
    }

    private static function setCookie(string $value, int $expires): void
    {
        setcookie(self::COOKIE, $value, ['expires' => $expires, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax',
            'secure' => 'https' === ($_SERVER['REQUEST_SCHEME'] ?? '') || 'on' === ($_SERVER['HTTPS'] ?? '')]);
    }

    // ------------------------------------------------------------------ Endpunkte (?fluid_demo_gate=challenge|start|end)

    public static function handle(string $step): never
    {
        if (!Demo::enabled()) {
            self::send(['ok' => false, 'error' => 'Die Demo ist ausgeschaltet.'], 404);
        }
        match ($step) {
            'challenge' => self::challenge(),
            'start' => self::start(),
            'end' => self::end(),
            default => self::send(['ok' => false, 'error' => 'Unbekannt.'], 400),
        };
    }

    private static function challenge(): never
    {
        self::limitOrFail('challenge');
        $salt = bin2hex(random_bytes(16));
        $exp = time() + self::CHALLENGE_TTL;
        $d = self::DIFFICULTY;
        self::send(['ok' => true, 'salt' => $salt, 'exp' => $exp, 'difficulty' => $d, 'sig' => self::sign("challenge|$salt|$exp|$d")]);
    }

    private static function start(): never
    {
        self::sameOrigin();
        self::limitOrFail('start');
        $in = $_POST;
        $salt = (string) ($in['salt'] ?? '');
        $exp = (int) ($in['exp'] ?? 0);
        $d = (int) ($in['difficulty'] ?? 0);
        $nonce = (string) ($in['nonce'] ?? '');
        $fail = static fn () => self::send(['ok' => false, 'error' => 'Die Prüfung hat nicht geklappt. Bitte noch einmal versuchen.'], 403);
        if ('' !== trim((string) ($in['website'] ?? ''))                   // Honigtopf: Menschen sehen das Feld nicht
            || (int) ($in['t'] ?? 0) < self::MIN_MS                           // zu schnell für einen Menschen
            || !preg_match('~^[a-f0-9]{32}$~', $salt) || !ctype_digit($nonce) || \strlen($nonce) > 12
            || $d < self::DIFFICULTY || $exp < time()
            || !hash_equals(self::sign("challenge|$salt|$exp|$d"), (string) ($in['sig'] ?? ''))
            || !self::leadingZeroBits(hash('sha256', $salt . $nonce, true), $d)
            || !self::once($salt)) {
            $fail();
        }
        $until = time() + self::SESSION;
        $rand = bin2hex(random_bytes(8));
        self::setCookie($until . '.' . $rand . '.' . self::sign('session|' . $until . '|' . $rand), $until);
        self::send(['ok' => true]);
    }

    private static function end(): never
    {
        self::sameOrigin();
        self::setCookie('', time() - 3600);
        self::send(['ok' => true]);
    }

    /** Schreibende Endpunkte nur von dieser Website aus */
    private static function sameOrigin(): void
    {
        if ('POST' !== ($_SERVER['REQUEST_METHOD'] ?? '')) {
            self::send(['ok' => false, 'error' => 'Nur POST.'], 405);
        }
        $origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
        if ('' !== $origin && parse_url($origin, PHP_URL_HOST) !== parse_url('//' . ($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST)) {
            self::send(['ok' => false, 'error' => 'Nicht erlaubt.'], 403);
        }
    }

    public static function leadingZeroBits(string $bin, int $bits): bool
    {
        for ($i = 0; $bits > 0; ++$i, $bits -= 8) {
            $byte = \ord($bin[$i] ?? "\xff");
            if ($bits >= 8 ? 0 !== $byte : 0 !== ($byte >> (8 - $bits))) {
                return false;
            }
        }

        return true;
    }

    /** Jede Aufgabe nur einmal lösen (Merkliste im Cache, räumt Abgelaufenes auf) */
    private static function once(string $salt): bool
    {
        $dir = Path::addonCache('fluid_demo', 'used');
        if (!is_dir($dir)) {
            @mkdir($dir, 0o775, true);
        }
        if (1 === random_int(1, 20)) {
            foreach (glob($dir . '/*') ?: [] as $f) {
                if (filemtime($f) < time() - 2 * self::CHALLENGE_TTL) {
                    @unlink($f);
                }
            }
        }
        $h = @fopen($dir . '/' . $salt, 'x');   // schlägt fehl, wenn es die Datei schon gibt
        if (!$h) {
            return false;
        }
        fclose($h);

        return true;
    }

    // ------------------------------------------------------------------ Begrenzung je IP

    /** Zählt die Anfrage und meldet, ob die Grenze erreicht ist */
    public static function limited(string $what): bool
    {
        [$max, $window] = self::LIMITS[$what];
        $ip = hash('sha256', (string) ($_SERVER['REMOTE_ADDR'] ?? '') . '|' . self::key());
        $dir = Path::addonCache('fluid_demo', 'rate');
        if (!is_dir($dir)) {
            @mkdir($dir, 0o775, true);
        }
        $h = @fopen($dir . '/' . $what . '-' . substr($ip, 0, 32), 'c+');
        if (!$h) {
            return false;
        }
        try {
            flock($h, LOCK_EX);
            $now = time();
            $hits = array_values(array_filter((array) json_decode((string) stream_get_contents($h), true), static fn ($t) => (int) $t > $now - $window));
            $over = \count($hits) >= $max;
            if (!$over) {
                $hits[] = $now;
            }
            ftruncate($h, 0);
            rewind($h);
            fwrite($h, (string) json_encode($hits));

            return $over;
        } finally {
            flock($h, LOCK_UN);
            fclose($h);
        }
    }

    private static function limitOrFail(string $what): void
    {
        if (self::limited($what)) {
            header('Retry-After: 300');
            self::send(['ok' => false, 'error' => 'Zu viele Versuche – bitte später noch einmal.'], 429);
        }
    }

    public static function send(array $data, int $status = 200): never
    {
        while (ob_get_level()) {
            ob_end_clean();
        }
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Robots-Tag: noindex');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}
