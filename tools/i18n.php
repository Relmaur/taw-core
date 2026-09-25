<?php

declare(strict_types=1);

/**
 * taw-core's translation tooling, with no dependencies (no wp-cli, no gettext).
 *
 *   php tools/i18n.php pot       write languages/taw-core.pot from src/ (PHP) and
 *                                resources/data-panel/src/ (TS/TSX)
 *   php tools/i18n.php po        merge each languages/taw-core-<locale>.po with the
 *                                .pot (keeps translations, drops strings no longer used)
 *   php tools/i18n.php compile   languages/taw-core-<locale>.po → .l10n.php (the
 *                                format WordPress 6.5+ loads first)
 *   php tools/i18n.php check     fail when either is out of date, or when a
 *                                translation call uses another domain or a
 *                                non-literal string (CI)
 *
 * The .pot has no creation date and references files without line numbers, so
 * it only changes when the strings do.
 */

const DOMAIN = 'taw-core';

/** Domains taw-core code may still use on purpose (theme-owned strings). */
const ALLOWED_OTHER = [
    'src/Core/Theme/Theme.php',          // load_theme_textdomain('taw-theme'): the theme's own domain
    'src/CLI/MakeBlockCommand.php',      // writes block code into the theme
];

/** gettext function → argument roles. */
const SPECS = [
    '__' => ['text', 'domain'], '_e' => ['text', 'domain'],
    'esc_html__' => ['text', 'domain'], 'esc_html_e' => ['text', 'domain'],
    'esc_attr__' => ['text', 'domain'], 'esc_attr_e' => ['text', 'domain'],
    '_x' => ['text', 'context', 'domain'], '_ex' => ['text', 'context', 'domain'],
    'esc_html_x' => ['text', 'context', 'domain'], 'esc_attr_x' => ['text', 'context', 'domain'],
    '_n' => ['text', 'plural', 'number', 'domain'], '_n_noop' => ['text', 'plural', 'domain'],
    '_nx' => ['text', 'plural', 'number', 'context', 'domain'], '_nx_noop' => ['text', 'plural', 'context', 'domain'],
];

$root = dirname(__DIR__);

/**
 * @return list<string> paths relative to $root
 */
function files(string $root, string $dir, array $extensions): array
{
    $out = [];
    if (!is_dir("$root/$dir")) {
        return $out;
    }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("$root/$dir", FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if (in_array($file->getExtension(), $extensions, true) && !str_contains($file->getPathname(), '/node_modules/')) {
            $out[] = substr($file->getPathname(), strlen($root) + 1);
        }
    }
    sort($out);

    return $out;
}

/**
 * Every translation call in one PHP file.
 *
 * @return list<array{fn: string, args: list<?string>, comment: string, line: int}>
 */
function phpCalls(string $code): array
{
    $tokens = token_get_all($code);
    $calls = [];
    $comment = '';
    $count = count($tokens);

    for ($i = 0; $i < $count; $i++) {
        $t = $tokens[$i];
        if (is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) {
            if (preg_match('/translators:.*/is', $t[1], $m)) {
                $comment = trim(preg_replace(['#\*/$#', '#^\s*(/\*+|\*|//)\s?#m'], '', $m[0]));
            }
            continue;
        }
        if (!is_array($t) || $t[0] !== T_STRING || !isset(SPECS[$t[1]])) {
            continue;
        }
        // Skip method calls/definitions: ->__( ::__( function __(
        $p = $i - 1;
        while ($p >= 0 && is_array($tokens[$p]) && $tokens[$p][0] === T_WHITESPACE) {
            $p--;
        }
        if ($p >= 0 && is_array($tokens[$p]) && in_array($tokens[$p][0], [T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NULLSAFE_OBJECT_OPERATOR], true)) {
            continue;
        }
        $n = $i + 1;
        while ($n < $count && is_array($tokens[$n]) && $tokens[$n][0] === T_WHITESPACE) {
            $n++;
        }
        if (($tokens[$n] ?? null) !== '(') {
            continue;
        }

        // Collect top-level arguments as literal strings (null when not literal).
        $args = [];
        $current = [];
        $depth = 0;
        for ($j = $n + 1; $j < $count; $j++) {
            $tok = $tokens[$j];
            if ($tok === '(' || $tok === '[' || $tok === '{') {
                $depth++;
            } elseif ($tok === ')' || $tok === ']' || $tok === '}') {
                if ($depth === 0) {
                    $args[] = $current;
                    break;
                }
                $depth--;
            } elseif ($tok === ',' && $depth === 0) {
                $args[] = $current;
                $current = [];
                continue;
            }
            $current[] = $tok;
        }

        $calls[] = [
            'fn'      => $t[1],
            'args'    => array_map('literal', $args),
            'comment' => $comment,
            'line'    => $t[2],
        ];
        $comment = '';
    }

    return $calls;
}

/**
 * A PHP argument's value when it's string literals joined with '.', else null.
 */
function literal(array $tokens): ?string
{
    $value = '';
    $expectString = true;
    foreach ($tokens as $tok) {
        if (is_array($tok) && in_array($tok[0], [T_WHITESPACE, T_COMMENT], true)) {
            continue;
        }
        if ($expectString && is_array($tok) && $tok[0] === T_CONSTANT_ENCAPSED_STRING) {
            $value .= $tok[1][0] === "'"
                ? strtr(substr($tok[1], 1, -1), ["\\\\" => "\\", "\\'" => "'"])
                : stripcslashes(substr($tok[1], 1, -1));
            $expectString = false;
        } elseif (!$expectString && $tok === '.') {
            $expectString = true;
        } else {
            return null;
        }
    }

    return $expectString ? null : $value;
}

/**
 * Translation calls in TS/TSX source: __ / _x / _n / _nx from @wordpress/i18n.
 *
 * @return list<array{fn: string, args: list<?string>, comment: string, line: int}>
 */
function jsCalls(string $code): array
{
    $calls = [];
    preg_match_all('/(?<![\w.$])(__|_x|_nx|_n)\s*\(/', $code, $matches, PREG_OFFSET_CAPTURE);
    foreach ($matches[1] as [$fn, $offset]) {
        $pos = $offset + strlen($fn);
        while ($code[$pos] !== '(') {
            $pos++;
        }
        $pos++;
        $args = [];
        $current = '';
        $depth = 0;
        $len = strlen($code);
        while ($pos < $len) {
            $c = $code[$pos];
            if ($c === "'" || $c === '"' || $c === '`') {
                $end = $pos + 1;
                while ($end < $len && $code[$end] !== $c) {
                    $end += $code[$end] === '\\' ? 2 : 1;
                }
                $current .= substr($code, $pos, $end - $pos + 1);
                $pos = $end + 1;
                continue;
            }
            if ($c === '(' || $c === '[' || $c === '{') {
                $depth++;
            } elseif ($c === ')' || $c === ']' || $c === '}') {
                if ($depth === 0) {
                    $args[] = $current;
                    break;
                }
                $depth--;
            } elseif ($c === ',' && $depth === 0) {
                $args[] = $current;
                $current = '';
                $pos++;
                continue;
            }
            $current .= $c;
            $pos++;
        }
        $before = substr($code, max(0, $offset - 300), min(300, $offset));
        $comment = preg_match('#/\*\s*translators:(.*?)\*/\s*$#s', rtrim($before), $m) ? 'translators:' . rtrim($m[1]) : '';
        $calls[] = [
            'fn'      => $fn,
            'args'    => array_map('jsLiteral', array_filter($args, static fn ($a) => trim($a) !== '')),
            'comment' => trim($comment),
            'line'    => substr_count($code, "\n", 0, $offset) + 1,
        ];
    }

    return $calls;
}

function jsLiteral(string $arg): ?string
{
    $arg = trim($arg);
    if (preg_match('/^([\'"])((?:\\\\.|(?!\1).)*)\1$/s', $arg, $m)) {
        return stripcslashes($m[2]);
    }
    if (preg_match('/^`([^`$]*)`$/s', $arg, $m)) {
        return $m[1];
    }

    return null;
}

/**
 * Scan every source file.
 *
 * @return array{entries: array<string, array<string, mixed>>, problems: list<string>}
 */
function scan(string $root): array
{
    $entries = [];
    $problems = [];
    $sources = [];
    foreach (files($root, 'src', ['php']) as $file) {
        $sources[] = [$file, phpCalls((string) file_get_contents("$root/$file")), SPECS];
    }
    $jsSpecs = ['__' => ['text', 'domain'], '_x' => ['text', 'context', 'domain'], '_n' => ['text', 'plural', 'number', 'domain'], '_nx' => ['text', 'plural', 'number', 'context', 'domain']];
    foreach (files($root, 'resources/data-panel/src', ['ts', 'tsx']) as $file) {
        if (str_contains($file, '.test.')) {
            continue;
        }
        $sources[] = [$file, jsCalls((string) file_get_contents("$root/$file")), $jsSpecs];
    }

    foreach ($sources as [$file, $calls, $specs]) {
        foreach ($calls as $call) {
            $roles = $specs[$call['fn']];
            $values = [];
            foreach ($roles as $k => $role) {
                $values[$role] = $call['args'][$k] ?? null;
            }
            $where = "$file:{$call['line']} {$call['fn']}()";
            if ($values['domain'] !== DOMAIN) {
                if (!in_array($file, ALLOWED_OTHER, true)) {
                    $problems[] = "$where: domain " . var_export($values['domain'], true) . ", expected '" . DOMAIN . "'";
                }
                continue;
            }
            if ($values['text'] === null || (array_key_exists('plural', $values) && $values['plural'] === null) || (array_key_exists('context', $values) && $values['context'] === null)) {
                $problems[] = "$where: not a literal string";
                continue;
            }
            $key = ($values['context'] ?? '') . "\4" . $values['text'];
            $entry = $entries[$key] ?? ['context' => $values['context'] ?? null, 'text' => $values['text'], 'plural' => null, 'refs' => [], 'comments' => []];
            $entry['plural'] ??= $values['plural'] ?? null;
            $entry['refs'][$file] = true;
            if ($call['comment'] !== '') {
                $entry['comments'][$call['comment']] = true;
            }
            $entries[$key] = $entry;
        }
    }
    ksort($entries, SORT_STRING);

    return ['entries' => $entries, 'problems' => $problems];
}

function poString(string $s): string
{
    $escaped = addcslashes($s, "\\\"\t\r");
    if (!str_contains($s, "\n")) {
        return '"' . str_replace("\n", '\n', $escaped) . '"';
    }
    $lines = preg_split('/(?<=\n)/', $escaped, -1, PREG_SPLIT_NO_EMPTY);

    return "\"\"\n" . implode("\n", array_map(static fn ($l) => '"' . str_replace("\n", '\n', $l) . '"', $lines));
}

/**
 * The .pot, or with $po a translation file merged with it: $po's header and
 * translations over the .pot's entries.
 */
function pot(array $entries, ?string $po = null): string
{
    if ($po !== null) {
        $parsed = readPo($po);
        $forms = [];
        foreach ($parsed['entries'] as $e) {
            ksort($e['forms']);
            $forms[($e['context'] ?? '') . "\4" . $e['text']] = array_values($e['forms']);
        }
    }
    $out = "# Translations for taw/core. Generated by `composer run i18n:pot`; don't edit by hand.\n"
        . "msgid \"\"\nmsgstr \"\"\n"
        . "\"Project-Id-Version: taw/core\\n\"\n"
        . "\"MIME-Version: 1.0\\n\"\n"
        . "\"Content-Type: text/plain; charset=UTF-8\\n\"\n"
        . "\"Content-Transfer-Encoding: 8bit\\n\"\n"
        . "\"X-Domain: " . DOMAIN . "\\n\"\n";
    foreach ($entries as $e) {
        $out .= "\n";
        foreach (array_keys($e['comments']) as $c) {
            foreach (explode("\n", $c) as $line) {
                $out .= '#. ' . trim($line) . "\n";
            }
        }
        $refs = array_keys($e['refs']);
        sort($refs);
        $out .= '#: ' . implode(' ', $refs) . "\n";
        if (preg_match('/%(\d+\$)?[sdf]/', $e['text'] . ($e['plural'] ?? ''))) {
            $out .= "#, php-format\n";
        }
        if ($e['context'] !== null) {
            $out .= 'msgctxt ' . poString($e['context']) . "\n";
        }
        $out .= 'msgid ' . poString($e['text']) . "\n";
        $translated = $po !== null ? ($forms[($e['context'] ?? '') . "\4" . $e['text']] ?? []) : [];
        if ($e['plural'] !== null) {
            $out .= 'msgid_plural ' . poString($e['plural']) . "\n"
                . 'msgstr[0] ' . poString($translated[0] ?? '') . "\n"
                . 'msgstr[1] ' . poString($translated[1] ?? '') . "\n";
        } else {
            $out .= 'msgstr ' . poString($translated[0] ?? '') . "\n";
        }
    }

    if ($po !== null) {
        // Keep the translation file's own header block.
        $header = substr($po, 0, (int) strpos($po, "\n\n"));
        $out = $header . substr($out, (int) strpos($out, "\n\n"));
    }

    return $out;
}

/**
 * Minimal .po reader: headers plus entries (context, singular, plural, forms).
 *
 * @return array{headers: array<string, string>, entries: list<array<string, mixed>>}
 */
function readPo(string $po): array
{
    $entries = [];
    $blocks = preg_split('/\n\s*\n/', str_replace("\r\n", "\n", $po));
    foreach ($blocks as $block) {
        $entry = ['context' => null, 'text' => null, 'plural' => null, 'forms' => [], 'fuzzy' => false];
        $field = null;
        foreach (explode("\n", $block) as $line) {
            if (str_starts_with($line, '#,') && str_contains($line, 'fuzzy')) {
                $entry['fuzzy'] = true;
            }
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            if (preg_match('/^(msgctxt|msgid_plural|msgid|msgstr(?:\[(\d+)\])?)\s+"(.*)"$/', $line, $m)) {
                $field = match (true) {
                    $m[1] === 'msgctxt' => 'context',
                    $m[1] === 'msgid' => 'text',
                    $m[1] === 'msgid_plural' => 'plural',
                    default => 'form' . ($m[2] !== '' ? $m[2] : '0'),
                };
                $value = stripcslashes($m[3]);
            } elseif (preg_match('/^"(.*)"$/', $line, $m) && $field !== null) {
                $value = stripcslashes($m[1]);
            } else {
                continue;
            }
            if (str_starts_with($field, 'form')) {
                $index = (int) substr($field, 4);
                $entry['forms'][$index] = ($entry['forms'][$index] ?? '') . $value;
            } else {
                $entry[$field] = ($entry[$field] ?? '') . $value;
            }
        }
        if ($entry['text'] !== null) {
            $entries[] = $entry;
        }
    }
    $headers = [];
    foreach ($entries as $i => $e) {
        if ($e['text'] === '' && $e['context'] === null) {
            foreach (explode("\n", $e['forms'][0] ?? '') as $h) {
                if (str_contains($h, ':')) {
                    [$k, $v] = explode(':', $h, 2);
                    $headers[strtolower(trim($k))] = trim($v);
                }
            }
            unset($entries[$i]);
        }
    }

    return ['headers' => $headers, 'entries' => array_values($entries)];
}

/**
 * A .po file as WordPress's .l10n.php array.
 */
function l10nPhp(string $po, string $locale): string
{
    $parsed = readPo($po);
    $messages = [];
    foreach ($parsed['entries'] as $e) {
        ksort($e['forms']);
        $forms = array_values($e['forms']);
        if ($e['fuzzy'] || $forms === [] || implode('', $forms) === '') {
            continue;
        }
        $key = ($e['context'] !== null ? $e['context'] . "\4" : '') . $e['text'];
        $messages[$key] = implode("\0", $forms);
    }
    ksort($messages, SORT_STRING);
    $data = [
        'domain'       => DOMAIN,
        'language'     => $locale,
        'plural-forms' => $parsed['headers']['plural-forms'] ?? 'nplurals=2; plural=(n != 1);',
        'messages'     => $messages,
    ];

    return "<?php\n// Generated from taw-core-$locale.po by `composer run i18n:compile`; don't edit by hand.\nreturn " . var_export($data, true) . ";\n";
}

$command = $argv[1] ?? '';
$scan = scan($root);
$potPath = "$root/languages/" . DOMAIN . '.pot';
$potText = pot($scan['entries']);
$compiled = [];
$merged = [];
foreach (glob("$root/languages/" . DOMAIN . '-*.po') ?: [] as $poPath) {
    $locale = substr(basename($poPath, '.po'), strlen(DOMAIN) + 1);
    $poText = (string) file_get_contents($poPath);
    $merged[$poPath] = pot($scan['entries'], $poText);
    $compiled[substr($poPath, 0, -3) . '.l10n.php'] = l10nPhp($poText, $locale);
}

switch ($command) {
    case 'pot':
        is_dir(dirname($potPath)) || mkdir(dirname($potPath), 0775, true);
        file_put_contents($potPath, $potText);
        fwrite(STDOUT, sprintf("%s: %d strings\n", basename($potPath), count($scan['entries'])));
        break;
    case 'po':
        foreach ($merged as $path => $text) {
            file_put_contents($path, $text);
            fwrite(STDOUT, basename($path) . "\n");
        }
        break;
    case 'compile':
        foreach ($compiled as $path => $php) {
            file_put_contents($path, $php);
            fwrite(STDOUT, basename($path) . "\n");
        }
        break;
    case 'check':
        $errors = $scan['problems'];
        if (!is_file($potPath) || file_get_contents($potPath) !== $potText) {
            $errors[] = 'languages/' . DOMAIN . '.pot is out of date: run composer run i18n:pot';
        }
        foreach ($merged as $path => $text) {
            if (file_get_contents($path) !== $text) {
                $errors[] = 'languages/' . basename($path) . ' is out of date with the .pot: run composer run i18n:po';
            }
        }
        foreach ($compiled as $path => $php) {
            if (!is_file($path) || file_get_contents($path) !== $php) {
                $errors[] = 'languages/' . basename($path) . ' is out of date: run composer run i18n:compile';
            }
        }
        foreach ($errors as $error) {
            fwrite(STDERR, $error . "\n");
        }
        if ($errors !== []) {
            exit(1);
        }
        fwrite(STDOUT, sprintf("i18n OK: %d strings\n", count($scan['entries'])));
        break;
    default:
        fwrite(STDERR, "Usage: php tools/i18n.php pot|po|compile|check\n");
        exit(2);
}
