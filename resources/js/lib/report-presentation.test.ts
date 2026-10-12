import { describe, expect, it } from 'vitest';
import {
    displayReportValue,
    reportChartMetric,
    reportDayCount,
    reportPreset,
} from '../../../shared/report-presentation';

const text = (key: string) =>
    ({ reportState_paid: 'Платено', reportField_net_minor: 'Нето' })[
        key as 'reportState_paid'
    ] ?? key;
const display = (key: string, value: string | number | null, locale = 'en') =>
    displayReportValue(key, value, locale, 'Europe/Skopje', text);
describe('report presentation preserves authoritative facts', () => {
    it('formats exact minor units beyond JavaScript safe integers and signed fractions', () => {
        expect(display('net_minor', '900719925474099301')).toContain(
            '9,007,199,254,740,993.01',
        );
        expect(display('net_minor', '-1')).toContain('-MKD');
        expect(display('net_minor', '-1')).toContain('0.01');
        expect(display('net_minor', '4000', 'mk')).toContain('40,00');
    });
    it('localizes state and comparison labels without treating identities as numbers', () => {
        expect(display('payment_state', 'paid', 'mk')).toBe('Платено');
        expect(display('metric', 'net_minor', 'mk')).toBe('Нето');
        expect(display('member_name', '000123')).toBe('000123');
        expect(display('public_id', '900719925474099301')).toBe(
            '900719925474099301',
        );
    });
    it('keeps unavailable rates distinct from zero and does not cap over-capacity', () => {
        expect(display('attendance_rate', null)).toBe('—');
        expect(display('attendance_rate', 0)).toBe('0%');
        expect(display('occupancy_rate', 125)).toBe('125%');
    });
    it('uses date-only calendar semantics and the studio timezone for instants', () => {
        expect(display('period', '2026-10-25')).toBe('25/10/2026');
        expect(display('as_of', '2026-10-25T00:30:00Z')).toContain('02:30');
        expect(display('paid_at', '2026-10-25 00:30:00+00')).toContain('02:30');
        expect(display('as_of', '2026-10-25T01:30:00Z')).toContain('02:30');
    });
    it('builds bounded presets from the server local day across leap and year boundaries', () => {
        expect(reportDayCount('2026-03-28', '2026-03-30')).toBe(3);
        expect(reportDayCount('2024-01-01', '2024-12-31')).toBe(366);
        expect(reportPreset('2024-03-01', 'week')).toEqual({
            from: '2024-02-24',
            until: '2024-03-01',
        });
        expect(reportPreset('2026-01-02', 'week').from).toBe('2025-12-27');
        expect(reportPreset('2024-12-31', 'year').from).toBe('2024-01-01');
        expect(reportPreset('2026-10-12', 'month').from).toBe('2026-10-01');
    });
    it('charts occupancy and cancellations with their own units', () => {
        expect(reportChartMetric('occupancy')).toBe('occupancy_rate');
        expect(reportChartMetric('cancellations')).toBe('cancelled');
        expect(reportChartMetric('revenue')).toBe('net_minor');
    });
});
