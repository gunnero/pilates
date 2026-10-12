/** Display only: server facts and export values remain exact and unchanged. */
export type ReportValue = string | number | boolean | null;

export function reportDayCount(from: string, until: string) {
    return (
        (Date.parse(`${until}T00:00:00Z`) - Date.parse(`${from}T00:00:00Z`)) /
            86400000 +
        1
    );
}

export function reportChartMetric(report: string) {
    if (report === 'revenue') return 'net_minor';
    if (report === 'settlement' || report === 'expiry') return 'memberships';
    if (report === 'activity') return 'members';
    if (report === 'occupancy') return 'occupancy_rate';
    if (report === 'cancellations') return 'cancelled';
    return 'present';
}

export function displayReportValue(
    key: string,
    value: ReportValue,
    locale: string,
    timezone: string,
    text: (key: string) => string,
): string {
    if (value === null) return '—';
    if (typeof value === 'boolean') return value ? '✓' : '—';
    if (key === 'metric') return text(`reportField_${value}`);
    if (
        ['state', 'status', 'payment_state', 'expiry', 'dimension'].includes(
            key,
        )
    )
        return text(`reportState_${value}`);
    const language = locale.startsWith('mk') ? 'mk-MK' : 'en-GB';
    if (key.endsWith('_minor') && /^-?\d+$/.test(String(value))) {
        const minor = BigInt(value);
        const whole = minor / 100n;
        const fraction = String((minor < 0n ? -minor : minor) % 100n).padStart(
            2,
            '0',
        );
        return new Intl.NumberFormat(language, {
            style: 'currency',
            currency: 'MKD',
            minimumFractionDigits: 2,
            maximumFractionDigits: 2,
        })
            .formatToParts(minor < 0n && whole === 0n ? -0 : whole)
            .map((part) => (part.type === 'fraction' ? fraction : part.value))
            .join('');
    }
    if (
        /^\d{4}-\d{2}-\d{2}$/.test(String(value)) &&
        ['period', 'from', 'until', 'starts_on', 'ends_on'].includes(key)
    )
        return new Intl.DateTimeFormat(language, { timeZone: 'UTC' }).format(
            new Date(`${value}T12:00:00Z`),
        );
    if (
        ['_at', 'as_of'].some((suffix) => key.endsWith(suffix)) &&
        typeof value === 'string'
    ) {
        // PostgreSQL text timestamps become ISO before parsing on Hermes/Safari.
        const instant = value
            .replace(' ', 'T')
            .replace(/([+-]\d{2})$/, '$1:00');
        if (!Number.isNaN(Date.parse(instant)))
            return new Intl.DateTimeFormat(language, {
                timeZone: timezone,
                dateStyle: 'medium',
                timeStyle: 'short',
            }).format(new Date(instant));
    }
    if (typeof value === 'number') {
        const number = new Intl.NumberFormat(language, {
            maximumFractionDigits: 2,
        }).format(value);
        return key.endsWith('_rate') || key === 'relative_percent'
            ? `${number}%`
            : number;
    }
    return String(value);
}

export function reportPreset(today: string, preset: 'month' | 'week' | 'year') {
    const day = new Date(`${today}T12:00:00Z`);
    if (preset === 'week') day.setUTCDate(day.getUTCDate() - 6);
    else if (preset === 'month') day.setUTCDate(1);
    else day.setUTCMonth(0, 1);
    return { from: day.toISOString().slice(0, 10), until: today };
}
