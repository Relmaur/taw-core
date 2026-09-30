/**
 * The function picker's reference (ADR-0015): every built-in function's
 * group, how it's written, what it does and an example. The site's own
 * functions (PHP `taw_expression_functions`) come with their label and
 * description and go under "This site".
 */
import { __ } from '@wordpress/i18n';
import { SIGNATURES, siteFunctions } from './expression';

export type FunctionGroup = 'logic' | 'numbers' | 'dates' | 'text' | 'lists' | 'site';

export interface FunctionDoc {
    name: string;
    group: FunctionGroup;
    /** How it's written, e.g. `round(number, digits?)`. */
    usage: string;
    description: string;
    example: string;
}

type Entry = [FunctionGroup, string, string, string];

/** Built-ins: group, usage (arguments after the name), description, example. */
function entries(): Record<string, Entry> {
    return {
        if: [
            'logic',
            '(test, then, else?)',
            __('Chooses: then when the test holds, else otherwise.', 'taw-core'),
            "@if(@stock > 0, 'In stock', 'Sold out')",
        ],
        coalesce: [
            'logic',
            '(a, b, …)',
            __('The first value that isn’t empty.', 'taw-core'),
            '@coalesce(@subtitle, @post.excerpt)',
        ],
        empty: [
            'logic',
            '(value)',
            __('Whether a value is empty.', 'taw-core'),
            "@if(@empty(@buy_link), 'Coming soon', 'Buy')",
        ],
        round: ['numbers', '(number, digits?)', __('Rounds a number.', 'taw-core'), '@round(@rating, 1)'],
        floor: ['numbers', '(number)', __('Rounds down.', 'taw-core'), '@floor(@price)'],
        ceil: ['numbers', '(number)', __('Rounds up.', 'taw-core'), '@ceil(@price)'],
        abs: ['numbers', '(number)', __('Without its sign.', 'taw-core'), '@abs(@balance)'],
        min: ['numbers', '(a, b, … or list)', __('The smallest number.', 'taw-core'), "@min(@column(@awards, 'year'))"],
        max: ['numbers', '(a, b, … or list)', __('The largest number.', 'taw-core'), "@max(@column(@awards, 'year'))"],
        number: [
            'numbers',
            '(number, digits?)',
            __('A number with the site’s thousands and decimal separators.', 'taw-core'),
            '@number(@visitors)',
        ],
        currency: [
            'numbers',
            "(number, 'MXN')",
            __('A price with its currency symbol.', 'taw-core'),
            "@(@price * 1.16).currency('MXN')",
        ],
        percent: ['numbers', '(number, digits?)', __('0.25 as 25%.', 'taw-core'), '@percent(@share)'],
        format: [
            'dates',
            "(date, 'F j, Y')",
            __('A date in a PHP date format.', 'taw-core'),
            "@post.date.format('F j, Y')",
        ],
        ago: ['dates', '(date)', __('How long ago: “3 days ago”, or “in 2 weeks”.', 'taw-core'), '@ago(@post.date)'],
        until: [
            'dates',
            '(date)',
            __('Time left until a date: “2 weeks”; empty once it’s past.', 'taw-core'),
            '@until(@event_date)',
        ],
        days_between: [
            'dates',
            '(from, to)',
            __('Whole days from one date to another.', 'taw-core'),
            '@days_between(@date.today, @event_date)',
        ],
        add_days: [
            'dates',
            '(date, days)',
            __('A date some days later (or earlier, with a negative number).', 'taw-core'),
            '@add_days(@date.today, 30)',
        ],
        year: ['dates', '(date)', __('The year. A 4-digit number is read as a year.', 'taw-core'), '@year(@post.date)'],
        month: ['dates', '(date)', __('The month’s name.', 'taw-core'), '@month(@post.date)'],
        day: ['dates', '(date)', __('The day of the month.', 'taw-core'), '@day(@post.date)'],
        weekday: ['dates', '(date)', __('The weekday’s name.', 'taw-core'), '@weekday(@event_date)'],
        upper: ['text', '(text)', __('UPPER CASE.', 'taw-core'), '@post.title.upper()'],
        lower: ['text', '(text)', __('lower case.', 'taw-core'), '@post.title.lower()'],
        capitalize: ['text', '(text)', __('A capital first letter.', 'taw-core'), '@capitalize(@author)'],
        default: [
            'text',
            "(value, 'text')",
            __('The text when the value is empty.', 'taw-core'),
            "@phone.default('—')",
        ],
        truncate: [
            'text',
            '(text, characters)',
            __('Shortens to a number of characters, with “…”.', 'taw-core'),
            '@post.excerpt.truncate(80)',
        ],
        words: ['text', '(text, words)', __('The first words, with “…”.', 'taw-core'), '@words(@post.excerpt, 12)'],
        word_count: ['text', '(text)', __('How many words.', 'taw-core'), '@word_count(@post.excerpt)'],
        replace: [
            'text',
            '(text, find, with)',
            __('Replaces text.', 'taw-core'),
            "@replace(@post.title, 'Vol.', 'Volume')",
        ],
        strip: ['text', '(text)', __('Without HTML.', 'taw-core'), '@strip(@blurb)'],
        slug: ['text', '(text)', __('As a URL slug: “my-title”.', 'taw-core'), '@slug(@post.title)'],
        urlencode: ['text', '(text)', __('Safe inside a link.', 'taw-core'), '/?s=@urlencode(@post.title)'],
        trim: ['text', '(text)', __('Without spaces at either end.', 'taw-core'), '@trim(@code)'],
        plural: [
            'text',
            "(number, 'one', 'many')",
            __('The number and the word that fits it: “3 awards”.', 'taw-core'),
            "@plural(@count(@awards), 'award', 'awards')",
        ],
        concat: ['text', '(a, b, …)', __('Joins text together.', 'taw-core'), "@concat(@first_name, ' ', @last_name)"],
        terms: ['lists', "('taxonomy')", __('The post’s terms in a taxonomy.', 'taw-core'), "@join(@terms('genre'))"],
        column: [
            'lists',
            "(rows, 'sub-field')",
            __('One sub-field of every repeater row.', 'taw-core'),
            "@column(@awards, 'name')",
        ],
        count: ['lists', '(list)', __('How many items: rows, related posts, files…', 'taw-core'), '@count(@awards)'],
        join: [
            'lists',
            "(list, ', ')",
            __('Items as text, with a separator.', 'taw-core'),
            "@join(@terms('genre'), ' · ')",
        ],
        first: ['lists', '(list)', __('The first item.', 'taw-core'), '@first(@related)'],
        last: ['lists', '(list)', __('The last item.', 'taw-core'), '@last(@related)'],
        sort: [
            'lists',
            '(list)',
            __('Items in order (numbers as numbers).', 'taw-core'),
            "@join(@sort(@terms('genre')))",
        ],
        reverse: [
            'lists',
            '(list)',
            __('Items in reverse order.', 'taw-core'),
            "@join(@reverse(@column(@awards, 'name')))",
        ],
        contains: [
            'lists',
            '(list, value)',
            __('Whether a list has a value (any case).', 'taw-core'),
            "@contains(@terms('genre'), 'fiction')",
        ],
        sum: ['lists', '(list)', __('Adds the numbers up.', 'taw-core'), "@sum(@column(@items, 'price'))"],
        avg: ['lists', '(list)', __('The average of the numbers.', 'taw-core'), "@avg(@column(@reviews, 'stars'))"],
    };
}

export function groupLabel(group: FunctionGroup): string {
    switch (group) {
        case 'logic':
            return __('Logic', 'taw-core');
        case 'numbers':
            return __('Numbers and money', 'taw-core');
        case 'dates':
            return __('Dates', 'taw-core');
        case 'text':
            return __('Text', 'taw-core');
        case 'lists':
            return __('Lists', 'taw-core');
        default:
            return __('This site', 'taw-core');
    }
}

/** Every function, built-ins first (in SIGNATURES' order within each group), then the site's own. */
export function functionDocs(): FunctionDoc[] {
    const built = entries();
    const order: FunctionGroup[] = ['logic', 'numbers', 'dates', 'text', 'lists'];
    const docs = Object.keys(SIGNATURES)
        .filter((name) => built[name] !== undefined)
        .map((name): FunctionDoc => {
            const [group, usage, description, example] = built[name];
            return { name, group, usage: `${name}${usage}`, description, example };
        })
        .sort((a, b) => order.indexOf(a.group) - order.indexOf(b.group));
    const site = Object.entries(siteFunctions()).map(([name, fn]): FunctionDoc => ({
        name,
        group: 'site',
        usage: `${name}(${fn.params.map((_, k) => (k < fn.required ? `x${k + 1}` : `x${k + 1}?`)).join(', ')})`,
        description: fn.description || fn.label,
        example: `@${name}(…)`,
    }));
    return [...docs, ...site];
}

/** Levenshtein distance, for “did you mean”. */
function distance(a: string, b: string): number {
    const row = Array.from({ length: b.length + 1 }, (_, j) => j);
    for (let i = 1; i <= a.length; i++) {
        let previous = row[0];
        row[0] = i;
        for (let j = 1; j <= b.length; j++) {
            const current = row[j];
            row[j] = Math.min(row[j] + 1, row[j - 1] + 1, previous + (a[i - 1] === b[j - 1] ? 0 : 1));
            previous = current;
        }
    }
    return row[b.length];
}

/** The known function closest to a misspelled name (at most 2 edits away), or null. */
export function didYouMean(
    name: string,
    known: string[] = [...Object.keys(SIGNATURES), ...Object.keys(siteFunctions())],
): string | null {
    if (known.includes(name)) return null;
    let best: string | null = null;
    let bestDistance = 3;
    for (const candidate of known) {
        const d = distance(name.toLowerCase(), candidate.toLowerCase());
        // A name that differs only in case (JOIN) is a typo too.
        if (d < bestDistance) {
            best = candidate;
            bestDistance = d;
        }
    }
    return best;
}
