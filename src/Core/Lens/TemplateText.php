<?php

declare(strict_types=1);

namespace TAW\Core\Lens;

// No ABSPATH guard: pure definitions with no include-time side effects.

/**
 * The text a PHP template writes itself, with its line numbers: the "template text" kind of TAW Lens's source
 * map (umbrella ADR-0006 § 7). Two sources:
 *
 * - HTML text runs between tags (`<p>Get started</p>` → "Get started"), kind `html`;
 * - PHP string literals with a letter in them, kind `i18n` when they're the first argument of a translation
 *   call (`__('Read more', 'taw-theme')`), `literal` otherwise.
 *
 * The page script compares an element's text with these exactly, so a literal that never reaches the page
 * (an array key, a class name) costs nothing but a few bytes. Read once per file per request.
 */
final class TemplateText
{
    /** Translation functions whose first argument is the text shown. */
    private const I18N = [
        '__', '_e', '_x', '_ex', '_n', '_nx',
        'esc_html__', 'esc_html_e', 'esc_html_x', 'esc_attr__', 'esc_attr_e', 'esc_attr_x',
    ];

    /** Longest source line kept for the inspect card's snippet. */
    private const LINE_MAX = 160;

    /** @var array<string, list<array{text: string, line: int, kind: string}>> */
    private static array $cache = [];

    /** @var array<string, list<string>> */
    private static array $lines = [];

    /**
     * @return list<array{text: string, line: int, kind: string}>
     */
    public static function scan(string $file): array
    {
        if (isset(self::$cache[$file])) {
            return self::$cache[$file];
        }
        $code = is_file($file) ? (string) file_get_contents($file) : '';

        return self::$cache[$file] = self::fromCode($code);
    }

    /**
     * @return list<array{text: string, line: int, kind: string}>
     */
    public static function fromCode(string $code): array
    {
        $out = [];
        try {
            $tokens = token_get_all($code);
        } catch (\Throwable) {
            return [];
        }
        $count = count($tokens);
        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            if (!is_array($token)) {
                continue;
            }
            [$type, $source, $line] = $token;
            if ($type === T_INLINE_HTML) {
                foreach (self::htmlRuns($source, $line) as $run) {
                    $out[] = $run;
                }
            } elseif ($type === T_CONSTANT_ENCAPSED_STRING) {
                $text = self::literal($source);
                if ($text === null) {
                    continue;
                }
                $out[] = ['text' => $text, 'line' => $line, 'kind' => self::translatable($tokens, $i) ? 'i18n' : 'literal'];
            }
        }

        return $out;
    }

    /**
     * Lines of a file, by number, for the inspect card's snippet: each asked line with the one before and
     * after it, right-trimmed and cut at 160 characters.
     *
     * @param list<int> $around
     * @return array<int, string> line number → source line (a JSON object: line numbers start at 1)
     */
    public static function lines(string $file, array $around): array
    {
        if (!isset(self::$lines[$file])) {
            $code = is_file($file) ? (string) file_get_contents($file) : '';
            self::$lines[$file] = $code === '' ? [] : (preg_split('/\r\n|\r|\n/', $code) ?: []);
        }
        $all = self::$lines[$file];
        $out = [];
        foreach ($around as $line) {
            for ($n = $line - 1; $n <= $line + 1; $n++) {
                if ($n >= 1 && isset($all[$n - 1])) {
                    $out[$n] = mb_substr(rtrim($all[$n - 1]), 0, self::LINE_MAX);
                }
            }
        }
        ksort($out, SORT_NUMERIC);

        return $out;
    }

    /** @internal */
    public static function resetForTests(): void
    {
        self::$cache = [];
        self::$lines = [];
    }

    /**
     * Text runs in a chunk of inline HTML: what sits between a `>` and the next `<`, plus a run at the very
     * start of the chunk (right after a closing PHP tag) unless it reads like the rest of a tag (`" class="…">`).
     *
     * @return list<array{text: string, line: int, kind: string}>
     */
    private static function htmlRuns(string $source, int $line): array
    {
        preg_match_all('/>([^<>]+)/', $source, $matches, PREG_OFFSET_CAPTURE);
        $runs = $matches[1];
        if (preg_match('/^([^<>]+)(?=<|$)/', $source, $first) && !preg_match('/["\'=]/', $first[1])) {
            array_unshift($runs, [$first[1], 0]);
        }

        $out = [];
        foreach ($runs as [$text, $offset]) {
            $clean = trim(preg_replace('/\s+/u', ' ', html_entity_decode($text, ENT_QUOTES | ENT_HTML5)) ?? '');
            if ($clean === '' || !preg_match('/[\p{L}\p{N}]/u', $clean)) {
                continue;
            }
            $lead = strlen($text) - strlen(ltrim($text));
            $at = $line + substr_count(substr($source, 0, $offset + $lead), "\n");
            $out[] = ['text' => $clean, 'line' => $at, 'kind' => 'html'];
        }

        return $out;
    }

    /** A quoted PHP string as its text, or null when it can't be visible text (no letter in it). */
    private static function literal(string $source): ?string
    {
        $quote = $source[0];
        $body = substr($source, 1, -1);
        $text = $quote === "'"
            ? str_replace(["\\'", '\\\\'], ["'", '\\'], $body)
            : stripcslashes($body);
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');

        return $text !== '' && preg_match('/\p{L}/u', $text) ? $text : null;
    }

    /**
     * Whether the string at $index is a translation call's first argument: the tokens before it, skipping
     * whitespace, are `(` and one of the translation functions.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function translatable(array $tokens, int $index): bool
    {
        $paren = self::previous($tokens, $index);
        if ($paren === null || $tokens[$paren] !== '(') {
            return false;
        }
        $name = self::previous($tokens, $paren);
        if ($name === null || !is_array($tokens[$name])) {
            return false;
        }

        return in_array(ltrim($tokens[$name][1], '\\'), self::I18N, true);
    }

    /**
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function previous(array $tokens, int $index): ?int
    {
        for ($j = $index - 1; $j >= 0; $j--) {
            if (!is_array($tokens[$j]) || !in_array($tokens[$j][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                return $j;
            }
        }

        return null;
    }
}
