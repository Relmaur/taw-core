/**
 * The metabox's datepicker stores dates in a jQuery UI format (default
 * "yy-mm-dd"). The panel supports its numeric tokens: yy (4-digit year),
 * mm/m (month), dd/d (day), plus literal separators. Other formats fall
 * back to a text input.
 */
const TOKEN = /yy|mm|m|dd|d/g;

export function isSupportedFormat(format: string): boolean {
    return (
        /^[ymd\-/. ]+$/.test(format) &&
        /yy/.test(format) &&
        /m/.test(format) &&
        /d/.test(format) &&
        !/yyyy/.test(format)
    );
}

export function formatDate(date: Date, format: string): string {
    const pad = (n: number) => String(n).padStart(2, '0');
    return format.replace(TOKEN, (token) => {
        switch (token) {
            case 'yy':
                return String(date.getFullYear());
            case 'mm':
                return pad(date.getMonth() + 1);
            case 'm':
                return String(date.getMonth() + 1);
            case 'dd':
                return pad(date.getDate());
            default:
                return String(date.getDate());
        }
    });
}

/** Parse a stored value in `format`; null when it doesn't match. */
export function parseDate(value: string, format: string): Date | null {
    const order: string[] = [];
    const pattern = format.replace(/[.*+?^${}()|[\]\\]/g, '\\$&').replace(TOKEN, (token) => {
        order.push(token);
        return token === 'yy' ? '(\\d{4})' : '(\\d{1,2})';
    });
    const match = new RegExp(`^${pattern}$`).exec(value.trim());
    if (!match) return null;

    let year = 0;
    let month = 1;
    let day = 1;
    order.forEach((token, i) => {
        const n = Number(match[i + 1]);
        if (token === 'yy') year = n;
        else if (token.startsWith('m')) month = n;
        else day = n;
    });
    const date = new Date(year, month - 1, day);

    return date.getFullYear() === year && date.getMonth() === month - 1 && date.getDate() === day ? date : null;
}
