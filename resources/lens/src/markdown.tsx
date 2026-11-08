/**
 * Claude's replies as the panel shows them: plain text with the little Markdown Claude uses (paragraphs, lists,
 * `code`, fenced code, **bold**, *emphasis*, links). Built as elements, never as HTML, so nothing in a reply
 * can inject markup; links open only for http(s).
 */
import type { ComponentChildren, JSX } from 'preact';

type Block =
    | { kind: 'p'; text: string }
    | { kind: 'h'; text: string }
    | { kind: 'code'; text: string }
    | { kind: 'ul' | 'ol'; items: string[] };

/** Splits a reply into blocks. A fence still streaming (no closing ```) is code so far. */
export function blocks(source: string): Block[] {
    const out: Block[] = [];
    const lines = source.replace(/\r\n?/g, '\n').split('\n');
    let paragraph: string[] = [];
    const flush = () => {
        if (paragraph.length) {
            out.push({ kind: 'p', text: paragraph.join('\n') });
            paragraph = [];
        }
    };
    for (let i = 0; i < lines.length; i++) {
        const line = lines[i];
        if (/^\s*```/.test(line)) {
            flush();
            const code: string[] = [];
            while (++i < lines.length && !/^\s*```/.test(lines[i])) {
                code.push(lines[i]);
            }
            out.push({ kind: 'code', text: code.join('\n') });
            continue;
        }
        const bullet = /^\s*[-*+]\s+(.*)$/.exec(line);
        const numbered = /^\s*\d+[.)]\s+(.*)$/.exec(line);
        if (bullet || numbered) {
            flush();
            const kind = bullet ? 'ul' : 'ol';
            const last = out[out.length - 1];
            const item = (bullet ?? numbered)![1];
            if (last && last.kind === kind) {
                last.items.push(item);
            } else {
                out.push({ kind, items: [item] });
            }
            continue;
        }
        const heading = /^#{1,6}\s+(.*)$/.exec(line);
        if (heading) {
            flush();
            out.push({ kind: 'h', text: heading[1] });
            continue;
        }
        if (line.trim() === '') {
            flush();
            continue;
        }
        // A continuation line of a list item belongs to it.
        const last = out[out.length - 1];
        if (paragraph.length === 0 && /^\s{2,}\S/.test(line) && last && (last.kind === 'ul' || last.kind === 'ol')) {
            last.items[last.items.length - 1] += ` ${line.trim()}`;
            continue;
        }
        paragraph.push(line);
    }
    flush();

    return out;
}

const SAFE_URL = /^https?:\/\//i;

/** Inline Markdown: `code`, **bold**, *em* / _em_, [text](url). */
export function inline(text: string): ComponentChildren[] {
    const out: ComponentChildren[] = [];
    const pattern =
        /(`+)([^`]+?)\1|\*\*([^*]+)\*\*|\*([^*\s][^*]*?)\*|(?<![\w])_([^_\s][^_]*?)_(?![\w])|\[([^\]]+)\]\(([^)\s]+)\)/g;
    let at = 0;
    let match: RegExpExecArray | null;
    let key = 0;
    while ((match = pattern.exec(text))) {
        if (match.index > at) {
            out.push(text.slice(at, match.index));
        }
        if (match[2] !== undefined) {
            out.push(<code key={key++}>{match[2]}</code>);
        } else if (match[3] !== undefined) {
            out.push(<strong key={key++}>{inline(match[3])}</strong>);
        } else if (match[4] !== undefined || match[5] !== undefined) {
            out.push(<em key={key++}>{inline(match[4] ?? match[5])}</em>);
        } else {
            const url = match[7];
            out.push(
                SAFE_URL.test(url) ? (
                    <a key={key++} href={url} target="_blank" rel="noopener noreferrer">
                        {match[6]}
                    </a>
                ) : (
                    match[6]
                ),
            );
        }
        at = match.index + match[0].length;
    }
    if (at < text.length) {
        out.push(text.slice(at));
    }

    return out;
}

const withBreaks = (text: string): ComponentChildren[] =>
    text.split('\n').flatMap((line, i) => (i === 0 ? inline(line) : [<br key={`br${i}`} />, ...inline(line)]));

export function Markdown({ source }: { source: string }): JSX.Element {
    return (
        <div class="md">
            {blocks(source).map((block, i) => {
                switch (block.kind) {
                    case 'code':
                        return (
                            <pre key={i}>
                                <code>{block.text}</code>
                            </pre>
                        );
                    case 'h':
                        return (
                            <p key={i}>
                                <strong>{inline(block.text)}</strong>
                            </p>
                        );
                    case 'ul':
                        return (
                            <ul key={i}>
                                {block.items.map((item, j) => (
                                    <li key={j}>{inline(item)}</li>
                                ))}
                            </ul>
                        );
                    case 'ol':
                        return (
                            <ol key={i}>
                                {block.items.map((item, j) => (
                                    <li key={j}>{inline(item)}</li>
                                ))}
                            </ol>
                        );
                    default:
                        return <p key={i}>{withBreaks(block.text)}</p>;
                }
            })}
        </div>
    );
}
