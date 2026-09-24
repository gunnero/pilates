export function parseMoney(value: string): number | null {
    if (!/^\d{1,7}([.,]\d{1,2})?$/.test(value)) return null;
    const [whole, fraction = ''] = value.replace(',', '.').split('.');
    const minor = Number(whole) * 100 + Number(fraction.padEnd(2, '0'));
    return Number.isSafeInteger(minor) && minor <= 100000000 ? minor : null;
}
export function money(minor: number): string {
    const sign = minor < 0 ? '-' : '';
    const n = Math.abs(minor);
    return `${sign}${Math.trunc(n / 100)}.${String(n % 100).padStart(2, '0')} MKD`;
}
