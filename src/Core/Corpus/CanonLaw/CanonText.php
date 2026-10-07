<?php

declare(strict_types=1);

namespace TAW\Core\Corpus\CanonLaw;

/**
 * Splits one canon's raw source text into what a reader actually shows —
 * pure string work, no PDO/WordPress dependency, shared by
 * {@see CanonLawReader} and {@see MysqlCanonLawReader} so both backends
 * normalize identically.
 *
 * The Spanish source the corpus is proofread against (the Holy See's
 * online edition) marks amended text inline, and those marks survive the
 * export as plain characters:
 *
 *  - a lone `n ` at the start of an amended canon or paragraph — either
 *    before the paragraph sign (`n § 1. …`) or right after it
 *    (`§ 1. n …`);
 *  - a leading `- ` on every canon of Book VI, which was replaced as a
 *    whole in 2021;
 *  - the printed page legend explaining the `n` mark, `(n Indica que el
 *    texto corresponde a la nueva versión)`, copied in as its own line;
 *  - a trailing `[Redacción original de los cánones modificados por …]:`
 *    block holding the pre-amendment wording, appended after the last
 *    canon of each amended group (so one block can carry the original
 *    text of several neighbouring canons, each line-prefixed with its own
 *    number — kept as-is, exactly as the source presents it).
 *
 * `parse()` strips the marks into a boolean `amended`, drops the legend,
 * and splits the original-wording block off into `amendment` — its
 * bracketed heading as `note` (which motu proprio, and when) and the
 * wording under it as `original_text`, never discarded: the 1983
 * promulgated text is exactly what a reader of this corpus may want to
 * compare against. Some source blocks carry the heading with no wording
 * under it; `original_text` is null then, and the note is still kept.
 *
 * Parsing is the fallback while the export has no structured columns for
 * this. Once the upstream proofreading app exports them, the readers
 * should prefer those columns and keep this only for older files.
 *
 * @phpstan-type Amendment array{note: string, original_text: string|null}
 * @phpstan-type ParsedCanon array{text: string, amended: bool, amendment: Amendment|null}
 */
final class CanonText
{
    private const ORIGINAL_BLOCK = '/^\[(Redacci[oó]n original[^\]]*)\]\s*:?\s*$/mu';

    private const LEGEND_LINE = '/^\(n:?\s+Indica que el texto[^)]*\)\s*$/mu';

    /** `n ` before or after a leading paragraph sign, at the start of a line. */
    private const LINE_MARK = '/^(§\s*\d+\s*[.:]\s*)?n\s+/mu';

    private const BOOK_MARK = '/^-\s+/u';

    /**
     * @return ParsedCanon
     */
    public static function parse(string $raw): array
    {
        $text = str_replace(["\r\n", "\r"], "\n", $raw);

        $amendment = null;
        if (preg_match(self::ORIGINAL_BLOCK, $text, $m, PREG_OFFSET_CAPTURE) === 1) {
            $offset = (int) $m[0][1];
            $body = trim(self::stripLegend(substr($text, $offset + strlen((string) $m[0][0]))));
            $amendment = ['note' => trim((string) $m[1][0]), 'original_text' => $body === '' ? null : $body];
            $text = substr($text, 0, $offset);
        }

        $text = self::stripLegend($text);

        $amended = false;
        $text = (string) preg_replace_callback(
            self::LINE_MARK,
            static function (array $m) use (&$amended): string {
                $amended = true;

                return $m[1] ?? '';
            },
            $text
        );
        $text = (string) preg_replace(self::BOOK_MARK, '', $text, 1, $count);
        if ($count > 0) {
            $amended = true;
        }

        return [
            'text' => trim($text),
            'amended' => $amended || $amendment !== null,
            'amendment' => $amendment,
        ];
    }

    /**
     * A search excerpt cut from the raw text (FTS `snippet()` or a
     * hand-built MySQL one) still carries the source marks — strip them,
     * and fold line breaks into spaces, since an excerpt is one line.
     * `<mark>` tags are left alone.
     */
    public static function cleanExcerpt(string $excerpt): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $excerpt);
        // A snippet can cut the legend or the original-wording heading
        // anywhere, so neither needs its closing bracket here.
        $text = (string) preg_replace('/\(n:?\s+Indica\b[^)\n]*\)?/u', '', $text);
        $text = (string) preg_replace('/\[Redacci[oó]n original[^\]\n]*\]?\s*:?/u', '', $text);
        $text = (string) preg_replace(self::LINE_MARK, '$1', $text);
        $text = (string) preg_replace(self::BOOK_MARK, '', $text);

        return trim((string) preg_replace('/\s*\n\s*/u', ' ', $text));
    }

    private static function stripLegend(string $text): string
    {
        return (string) preg_replace(self::LEGEND_LINE, '', $text);
    }
}
