export function dateTime(
    value: string,
    timezone: string,
    locale: string,
): string {
    return new Intl.DateTimeFormat(locale === 'mk' ? 'mk-MK' : 'en-GB', {
        dateStyle: 'medium',
        timeStyle: 'short',
        timeZone: timezone,
    }).format(new Date(value));
}
export function money(minor: number, currency: string, locale: string): string {
    return new Intl.NumberFormat(locale === 'mk' ? 'mk-MK' : 'en-GB', {
        style: 'currency',
        currency,
    }).format(minor / 100);
}
export function wallTime(minutes: number) {
    return `${Math.floor(minutes / 60)
        .toString()
        .padStart(2, '0')}:${(minutes % 60).toString().padStart(2, '0')}`;
}
