/**
 * Dates are formatted in UTC and in the page language, never in the runtime's
 * default zone or locale. The Next.js server runs in UTC while a visitor's
 * browser runs in their local zone, so a timestamp shortly after midnight UTC
 * would otherwise hydrate with a different calendar day than the server
 * rendered (found by the cold-clone verification on 2026-09-02).
 */
const dateOptions: Intl.DateTimeFormatOptions = {
    year: 'numeric',
    month: 'short',
    day: 'numeric',
    timeZone: 'UTC',
};

const dateTimeOptions: Intl.DateTimeFormatOptions = {
    ...dateOptions,
    hour: '2-digit',
    minute: '2-digit',
    hourCycle: 'h23',
    timeZoneName: 'short',
};

export function formatDate(iso: string, lang: string): string {
    return new Intl.DateTimeFormat(lang, dateOptions).format(new Date(iso));
}

export function formatDateTime(iso: string, lang: string): string {
    return new Intl.DateTimeFormat(lang, dateTimeOptions).format(new Date(iso));
}
