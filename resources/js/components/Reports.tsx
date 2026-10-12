import { useEffect, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Link, useSearchParams } from 'react-router-dom';
import { api, errorMessage, type Organization } from '../lib/api';

import {
    displayReportValue,
    reportDayCount,
    reportChartMetric,
    reportPreset,
} from '../../../shared/report-presentation';

type Value = string | number | boolean | null;
export type ReportData = {
    meta: {
        report: string;
        from: string;
        until: string;
        as_of: string;
        today: string;
        timezone: string;
        basis: string;
        view: string;
    };
    totals: Record<string, Value>;
    rows: Record<string, Value>[];
    columns: string[];
    options: Record<
        string,
        { rows: { id: string; label: string }[]; truncated: boolean }
    >;
    pagination?: { total: number; page: number; last_page: number };
    dimensions?: Record<
        string,
        { rows: Record<string, Value>[]; truncated: boolean }
    >;
    comparison: null | {
        from: string;
        until: string;
        totals: Record<string, Value>;
        rows: Record<string, Value>[];
        changes: Record<
            string,
            {
                current: Value;
                previous: Value;
                absolute: Value;
                relative_percent: Value;
                percentage_points: Value;
            }
        >;
    };
};
const reportNames = [
    'revenue',
    'settlement',
    'activity',
    'expiry',
    'attendance',
    'occupancy',
    'cancellations',
    'popularity',
] as const;
function ReportView({ organization }: { organization: Organization }) {
    const { t, i18n } = useTranslation();
    const [searchParams, setSearchParams] = useSearchParams();
    const allowed = organization.permissions.includes('reports.view');
    const finance =
        organization.permissions.includes('payments.view') &&
        organization.role !== 'trainer';
    const population =
        organization.location_scope === 'all' &&
        organization.permissions.includes('members.view') &&
        organization.permissions.includes('memberships.view') &&
        organization.role !== 'trainer';
    const operational =
        organization.permissions.includes('sessions.view') &&
        organization.permissions.includes('attendance.view');
    const names = reportNames.filter((name) =>
        ['revenue', 'settlement'].includes(name)
            ? finance
            : ['activity', 'expiry'].includes(name)
              ? population
              : operational,
    );
    const [filters, setFilters] = useState<Record<string, string>>(() => ({
        report: names[0] ?? 'attendance',
        comparison: 'previous',
        granularity: 'daily',
        ...(searchParams.get('studio') === organization.id
            ? Object.fromEntries(
                  [...searchParams].filter(
                      ([key, value]) =>
                          [
                              'from',
                              'until',
                              'granularity',
                              'comparison',
                              'compare_from',
                              'compare_until',
                              'group_id',
                              'trainer_id',
                              'plan_id',
                              'state',
                              'payment_state',
                              'expiry',
                              'rank',
                              'top',
                              'view',
                              'page',
                          ].includes(key) ||
                          (key === 'report' &&
                              names.includes(value as (typeof names)[number])),
                  ),
              )
            : {}),
    }));
    useEffect(() => {
        if (allowed)
            setSearchParams(
                { studio: organization.id, ...filters },
                { replace: true },
            );
    }, [allowed, organization.id, filters, setSearchParams]);
    const [revision, setRevision] = useState(0);
    const [data, setData] = useState<ReportData | null>(null);
    const [options, setOptions] = useState<ReportData['options']>({});
    const [resolvedDates, setResolvedDates] = useState({ from: '', until: '' });
    const [error, setError] = useState('');
    const [downloading, setDownloading] = useState(false);
    const display = (key: string, value: Value) =>
        displayReportValue(
            key,
            value,
            i18n.language,
            organization.timezone,
            (key) => t(key),
        );
    const query = new URLSearchParams(filters).toString();
    const base = `/api/v1/organizations/${organization.id}/reports`;
    useEffect(() => {
        if (
            !allowed ||
            (filters.comparison === 'custom' &&
                (!filters.compare_from || !filters.compare_until))
        )
            return;
        const controller = new AbortController();
        api.get<{ data: ReportData }>(`${base}?${query}`, controller.signal)
            .then((r) => {
                if (!controller.signal.aborted) {
                    setData(r.data);
                    setOptions(r.data.options);
                    setResolvedDates({
                        from: r.data.meta.from,
                        until: r.data.meta.until,
                    });
                    setError('');
                }
            })
            .catch((e) => {
                if (!controller.signal.aborted) {
                    setData(null);
                    setOptions({});
                    setError(errorMessage(e));
                }
            });
        return () => controller.abort();
    }, [
        allowed,
        base,
        query,
        revision,
        filters.comparison,
        filters.compare_from,
        filters.compare_until,
    ]);
    // Downloads are scoped to the mounted tenant component and aborted on unmount.
    const downloadController = useRef<AbortController | null>(null);
    useEffect(() => () => downloadController.current?.abort(), []);
    function change(key: string, value: string) {
        setData(null);
        setError('');
        setFilters((old) => {
            const next: Record<string, string> = { ...old, page: '1' };
            if (key === 'comparison' && value !== 'custom') {
                delete next.compare_from;
                delete next.compare_until;
            }
            if (value) next[key] = value;
            else delete next[key];
            return next;
        });
    }
    async function download(format: string) {
        if (downloading) return;
        const controller = new AbortController();
        downloadController.current = controller;
        setDownloading(true);
        setError('');
        try {
            const response = await fetch(`${base}?${query}&format=${format}`, {
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/octet-stream',
                    'Accept-Language': i18n.language,
                },
                signal: controller.signal,
            });
            if (!response.ok) {
                const detail =
                    response.status === 422
                        ? await response.json().catch(() => null)
                        : null;
                throw new Error(
                    typeof detail?.message === 'string'
                        ? detail.message
                        : t('reportExportError'),
                );
            }
            const blob = await response.blob();
            if (controller.signal.aborted) return;
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = `gt-${filters.report}.${format}`;
            a.click();
            URL.revokeObjectURL(url);
        } catch (e) {
            if (!controller.signal.aborted)
                setError(e instanceof Error ? e.message : t('error'));
        } finally {
            if (!controller.signal.aborted) setDownloading(false);
        }
    }
    function table(rows: Record<string, Value>[], columns?: string[]) {
        const original = columns ?? Object.keys(rows[0] ?? {});
        const keys = original.includes('label')
            ? [
                  'label',
                  ...original.filter(
                      (key) => key !== 'label' && key !== 'dimension_id',
                  ),
                  ...original.filter((key) => key === 'dimension_id'),
              ]
            : original;
        return (
            <div
                className="report-table"
                tabIndex={0}
                role="region"
                aria-label={t('reportTable')}
            >
                <table>
                    <thead>
                        <tr>
                            {keys.map((k) => (
                                <th key={k}>
                                    {t(`reportField_${k}`, { defaultValue: k })}
                                </th>
                            ))}
                        </tr>
                    </thead>
                    <tbody>
                        {rows.map((r, i) => (
                            <tr
                                key={String(r.public_id ?? r.dimension_id ?? i)}
                            >
                                {keys.map((k) => (
                                    <td key={k}>
                                        {display(
                                            [
                                                'current',
                                                'previous',
                                                'absolute',
                                            ].includes(k) &&
                                                typeof r.metric === 'string'
                                                ? r.metric
                                                : k,
                                            r[k] ?? null,
                                        )}
                                    </td>
                                ))}
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        );
    }
    if (!allowed || names.length === 0) return <p>{t('forbidden')}</p>;
    return (
        <section className="report-workspace">
            <h2>{t('reports')}</h2>
            <p>{t('reportReadOnly')}</p>
            <form
                className="member-filters"
                onSubmit={(e) => {
                    e.preventDefault();
                    const values = Object.fromEntries(
                        new FormData(e.currentTarget),
                    );
                    setData(null);
                    setFilters(
                        Object.fromEntries(
                            Object.entries(values)
                                .filter(([, v]) => v !== '')
                                .map(([k, v]) => [k, String(v)]),
                        ),
                    );
                }}
            >
                <label>
                    {t('reportType')}
                    <select
                        name="report"
                        value={filters.report}
                        onChange={(e) => {
                            setData(null);
                            setOptions({});
                            setFilters({
                                report: e.target.value,
                                granularity: filters.granularity,
                                comparison: 'previous',
                            });
                        }}
                    >
                        {names.map((n) => (
                            <option key={n} value={n}>
                                {t(`report_${n}`)}
                            </option>
                        ))}
                    </select>
                </label>
                <label>
                    {t('opsFrom')}
                    <input
                        key={`from-${filters.from ?? resolvedDates.from}`}
                        type="date"
                        name="from"
                        defaultValue={filters.from ?? resolvedDates.from}
                    />
                </label>
                <label>
                    {t('opsUntil')}
                    <input
                        key={`until-${filters.until ?? resolvedDates.until}`}
                        type="date"
                        name="until"
                        defaultValue={filters.until ?? resolvedDates.until}
                    />
                </label>
                <label>
                    {t('reportGranularity')}
                    <select
                        name="granularity"
                        defaultValue={filters.granularity}
                    >
                        {['daily', 'weekly', 'monthly'].map((k) => (
                            <option key={k} value={k}>
                                {t(`report_${k}`)}
                            </option>
                        ))}
                    </select>
                </label>
                <label>
                    {t('reportComparison')}
                    <select
                        name="comparison"
                        value={filters.comparison}
                        onChange={(e) => change('comparison', e.target.value)}
                    >
                        {['previous', 'custom', 'none'].map((k) => (
                            <option key={k} value={k}>
                                {t(`report_${k}`)}
                            </option>
                        ))}
                    </select>
                </label>
                {filters.comparison === 'custom' && (
                    <>
                        <label>
                            {t('reportCompareFrom')}
                            <input
                                type="date"
                                name="compare_from"
                                required
                                defaultValue={filters.compare_from}
                            />
                        </label>
                        <label>
                            {t('reportCompareUntil')}
                            <input
                                type="date"
                                name="compare_until"
                                required
                                defaultValue={filters.compare_until}
                            />
                        </label>
                    </>
                )}
                {Object.entries(options).map(([key, options]) => (
                    <label key={key}>
                        {t(`reportField_${key}`)}
                        <select name={key} defaultValue={filters[key] ?? ''}>
                            <option value="">{t('reportAll')}</option>
                            {options.rows.map((o) => (
                                <option value={o.id} key={o.id}>
                                    {o.label}
                                </option>
                            ))}
                        </select>
                        {options.truncated && (
                            <span>{t('reportOptionsLimited')}</span>
                        )}
                    </label>
                ))}
                {filters.report === 'settlement' && (
                    <label>
                        {t('reportField_payment_state')}
                        <select
                            name="payment_state"
                            defaultValue={filters.payment_state ?? ''}
                        >
                            <option value="">{t('reportAll')}</option>
                            {[
                                'unpaid',
                                'partially_paid',
                                'paid',
                                'overpaid',
                            ].map((k) => (
                                <option key={k} value={k}>
                                    {t(`reportState_${k}`)}
                                </option>
                            ))}
                        </select>
                    </label>
                )}
                {filters.report === 'expiry' && (
                    <label>
                        {t('report_expiry')}
                        <select
                            name="expiry"
                            defaultValue={filters.expiry ?? ''}
                        >
                            <option value="">{t('reportAll')}</option>
                            {['expired', '7', '14', '30', 'later'].map((k) => (
                                <option key={k} value={k}>
                                    {t(`reportState_${k}`)}
                                </option>
                            ))}
                        </select>
                    </label>
                )}
                {filters.report === 'settlement' && (
                    <label>
                        {t('reportField_state')}
                        <select name="state" defaultValue={filters.state ?? ''}>
                            <option value="">{t('reportAll')}</option>
                            {['active', 'upcoming', 'expired', 'cancelled'].map(
                                (k) => (
                                    <option key={k} value={k}>
                                        {t(`reportState_${k}`)}
                                    </option>
                                ),
                            )}
                        </select>
                    </label>
                )}
                {filters.report === 'popularity' && (
                    <>
                        <label>
                            {t('reportRank')}
                            <select
                                name="rank"
                                defaultValue={filters.rank ?? 'present'}
                            >
                                {['present', 'reserved', 'waiting'].map((k) => (
                                    <option key={k} value={k}>
                                        {t(`reportField_${k}`)}
                                    </option>
                                ))}
                            </select>
                        </label>
                        <label>
                            {t('reportTop')}
                            <input
                                name="top"
                                type="number"
                                min="1"
                                max="50"
                                defaultValue={filters.top ?? '10'}
                            />
                        </label>
                    </>
                )}
                <input
                    type="hidden"
                    name="view"
                    value={filters.view ?? 'summary'}
                />
                <button type="submit">{t('reportApply')}</button>
            </form>
            <div className="actions">
                <button
                    onClick={() => {
                        setData(null);
                        setRevision((v) => v + 1);
                    }}
                >
                    {t('opsRefresh')}
                </button>
                <button
                    onClick={() => {
                        setData(null);
                        setFilters({
                            report: filters.report,
                            comparison: 'previous',
                            granularity: 'daily',
                        });
                    }}
                >
                    {t('reportReset')}
                </button>
                <button
                    onClick={() =>
                        change(
                            'view',
                            filters.view === 'details' ? 'summary' : 'details',
                        )
                    }
                >
                    {t(
                        filters.view === 'details'
                            ? 'reportSummary'
                            : 'reportDetails',
                    )}
                </button>
                <button
                    disabled={downloading || !data}
                    onClick={() => void download('csv')}
                >
                    {t('reportCsv')}
                </button>
                <button
                    disabled={downloading || !data}
                    onClick={() => void download('xlsx')}
                >
                    {t('reportXlsx')}
                </button>
            </div>
            <div className="actions" aria-label={t('reportPresets')}>
                {(['month', 'week', 'year'] as const).map((preset) => (
                    <button
                        key={preset}
                        disabled={!data?.meta.today}
                        onClick={() => {
                            if (!data) return;
                            setFilters((old) => ({
                                ...old,
                                ...reportPreset(data.meta.today, preset),
                                page: '1',
                            }));
                            setData(null);
                        }}
                    >
                        {t(`reportPreset_${preset}`)}
                    </button>
                ))}
            </div>
            <p>{t('reportLimits')}</p>
            {downloading && <p role="status">{t('reportPreparing')}</p>}
            {error && <p role="alert">{error}</p>}
            {!data && !error && (
                <p role="status">
                    {t(
                        filters.comparison === 'custom' &&
                            (!filters.compare_from || !filters.compare_until)
                            ? 'reportSelectComparison'
                            : 'loading',
                    )}
                </p>
            )}
            {data && (
                <>
                    <p>
                        {display('from', data.meta.from)} —{' '}
                        {display('until', data.meta.until)} ·{' '}
                        {data.meta.timezone}
                    </p>
                    <p>{t(`reportBasis_${data.meta.basis}`)}</p>
                    <p>
                        {t('reportAsOf')}: {display('as_of', data.meta.as_of)}
                    </p>
                    <p>{t(`reportDefinition_${filters.report}`)}</p>
                    <p>
                        {t('reportPeriodDays')}:{' '}
                        {reportDayCount(data.meta.from, data.meta.until)}
                    </p>
                    <dl className="report-totals">
                        {Object.entries(data.totals).map(([k, v]) => (
                            <div key={k}>
                                <dt>
                                    {t(`reportField_${k}`, { defaultValue: k })}
                                </dt>
                                <dd>{display(k, v)}</dd>
                            </div>
                        ))}
                    </dl>
                    {filters.view !== 'details' && (
                        <figure aria-label={t('reportChart')}>
                            <figcaption>{t('reportChart')}</figcaption>
                            {data.rows.slice(0, 60).map((r, i) => {
                                const key = reportChartMetric(filters.report);
                                const max = Math.max(
                                    1,
                                    ...data.rows.map((row) =>
                                        Math.abs(Number(row[key] ?? 0)),
                                    ),
                                );
                                const value = Number(r[key] ?? 0);
                                return (
                                    <div className="report-bar" key={i}>
                                        <span>
                                            {display(
                                                'period' in r
                                                    ? 'period'
                                                    : 'payment_state' in r
                                                      ? 'payment_state'
                                                      : 'expiry' in r
                                                        ? 'expiry'
                                                        : 'status',
                                                r.period ??
                                                    r.payment_state ??
                                                    r.expiry ??
                                                    r.status ??
                                                    '',
                                            )}
                                        </span>
                                        {r[key] !== null && (
                                            <meter
                                                min={Math.min(
                                                    0,
                                                    ...data.rows.map((row) =>
                                                        Number(row[key] ?? 0),
                                                    ),
                                                )}
                                                max={max}
                                                value={value}
                                                aria-label={`${t(`reportField_${key}`)} ${display(key, r[key] ?? null)}`}
                                            />
                                        )}
                                        <span>
                                            {display(key, r[key] ?? null)}
                                        </span>
                                    </div>
                                );
                            })}
                            <p>{t('reportChartLimit')}</p>
                        </figure>
                    )}
                    {data.rows.length === 0 ? (
                        <p>{t('reportEmpty')}</p>
                    ) : (
                        table(data.rows, data.columns)
                    )}
                    {data.pagination && (
                        <nav aria-label={t('reportDetails')}>
                            <button
                                disabled={data.pagination.page <= 1}
                                onClick={() =>
                                    change(
                                        'page',
                                        String(data.pagination!.page - 1),
                                    )
                                }
                            >
                                {t('previous')}
                            </button>
                            <span>
                                {data.pagination.page} /{' '}
                                {data.pagination.last_page} ·{' '}
                                {data.pagination.total}
                            </span>
                            <button
                                disabled={
                                    data.pagination.page >=
                                    data.pagination.last_page
                                }
                                onClick={() =>
                                    change(
                                        'page',
                                        String(data.pagination!.page + 1),
                                    )
                                }
                            >
                                {t('next')}
                            </button>
                        </nav>
                    )}
                    {data.dimensions &&
                        Object.entries(data.dimensions).map(([key, d]) => (
                            <section key={key}>
                                <h3>{t(`reportDimension_${key}`)}</h3>
                                {table(d.rows)}
                                {d.truncated && (
                                    <p>{t('reportRankingLimited')}</p>
                                )}
                            </section>
                        ))}
                    {data.comparison ? (
                        <section>
                            <h3>
                                {t('reportComparison')}:{' '}
                                {display('from', data.comparison.from)} —{' '}
                                {display('until', data.comparison.until)}
                            </h3>
                            <p>
                                {t('reportPeriodDays')}:{' '}
                                {reportDayCount(
                                    data.comparison.from,
                                    data.comparison.until,
                                )}
                            </p>
                            {table(
                                Object.entries(data.comparison.changes).map(
                                    ([metric, c]) => ({ metric, ...c }),
                                ),
                            )}
                        </section>
                    ) : (
                        <p>
                            {t(
                                filters.comparison === 'none'
                                    ? 'reportNoComparison'
                                    : 'reportNoHistoricalSnapshot',
                            )}
                        </p>
                    )}
                    <Link
                        to={
                            ['revenue', 'settlement'].includes(filters.report)
                                ? '/payments'
                                : ['activity', 'expiry'].includes(
                                        filters.report,
                                    )
                                  ? '/memberships'
                                  : '/operations'
                        }
                    >
                        {t('reportFollowUp')}
                    </Link>
                </>
            )}
        </section>
    );
}

export function Reports({ organization }: { organization: Organization }) {
    const scope = JSON.stringify([
        organization.id,
        organization.role,
        organization.permissions,
        organization.location_scope,
    ]);
    return <ReportView key={scope} organization={organization} />;
}
