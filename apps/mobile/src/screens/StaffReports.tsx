import {
    displayReportValue,
    reportDayCount,
    reportChartMetric,
    reportPreset,
    type ReportValue,
} from '../../../../shared/report-presentation';
import { useEffect, useRef, useState } from 'react';
import { AppState, View } from 'react-native';
import {
    Body,
    Button,
    Field,
    Heading,
    Screen,
    ErrorMessage,
} from '../components/ui';
import { useLocale } from '../locales/context';
import { reports } from '../locales/reports';
import type { OperationsClient } from '../api/operations';
import type { ReportData } from '../api/reports';
import { shareReport, clearReportFiles } from '../api/report-export';

export function StaffReports({
    client,
    organization,
    exit,
}: {
    client: OperationsClient;
    organization: string;
    exit: () => void;
}) {
    const { locale, setLocale, t } = useLocale();
    const text = (key: string) =>
        reports[locale][key as keyof typeof reports.en] ?? key;
    const [context, setContext] = useState<{
        permissions: string[];
        role: string;
        location_scope: string;
    } | null>(null);
    const [report, setReport] = useState('');
    const [from, setFrom] = useState('');
    const [until, setUntil] = useState('');
    const [compareFrom, setCompareFrom] = useState('');
    const [compareUntil, setCompareUntil] = useState('');
    const [filters, setFilters] = useState<Record<string, string>>({
        comparison: 'previous',
        granularity: 'daily',
    });
    const [data, setData] = useState<ReportData | null>(null);
    const [error, setError] = useState<unknown>(null);
    const [revision, setRevision] = useState(0);
    const [busy, setBusy] = useState(false);
    const [visiblePage, setVisiblePage] = useState(0);
    const download = useRef<AbortController | null>(null);
    const display = (key: string, value: ReportValue) =>
        displayReportValue(
            key,
            value,
            locale,
            data?.meta.timezone ?? 'UTC',
            text,
        );
    const base = `/api/v1/organizations/${organization}`;
    const query = new URLSearchParams({ ...filters, report }).toString();
    useEffect(() => {
        const c = new AbortController();
        const generation = client.generation;
        client
            .request<{
                data: {
                    permissions: string[];
                    role: string;
                    location_scope: string;
                };
            }>(`${base}/context`, 'GET', undefined, c.signal)
            .then((r) => {
                if (c.signal.aborted || generation !== client.generation)
                    return;
                setData(null);
                setContext(r.data);
                setReport(
                    (old) =>
                        old ||
                        (r.data.permissions.includes('payments.view') &&
                        r.data.role !== 'trainer'
                            ? 'revenue'
                            : 'attendance'),
                );
            })
            .catch((e) => {
                if (!c.signal.aborted) {
                    setContext(null);
                    setData(null);
                    setError(e);
                }
            });
        return () => c.abort();
    }, [base, client, revision]);
    useEffect(() => {
        if (
            !report ||
            !context?.permissions.includes('reports.view') ||
            (filters.comparison === 'custom' &&
                (!filters.compare_from || !filters.compare_until))
        )
            return;
        const c = new AbortController();
        const generation = client.generation;
        client
            .request<{ data: ReportData }>(
                `${base}/reports?${query}`,
                'GET',
                undefined,
                c.signal,
            )
            .then((r) => {
                if (c.signal.aborted || generation !== client.generation)
                    return;
                setData(r.data);
                setError(null);
                if (!filters.from) {
                    setFrom(r.data.meta.from);
                    setUntil(r.data.meta.until);
                }
            })
            .catch((e) => {
                if (!c.signal.aborted && generation === client.generation) {
                    setData(null);
                    setError(e);
                }
            });
        return () => c.abort();
    }, [
        base,
        client,
        query,
        report,
        context,
        revision,
        filters.from,
        filters.comparison,
        filters.compare_from,
        filters.compare_until,
    ]);
    useEffect(() => {
        clearReportFiles();
        const listener = AppState.addEventListener('change', (state) => {
            if (state === 'active') {
                setData(null);
                setRevision((v) => v + 1);
            }
        });
        return () => {
            listener.remove();
            download.current?.abort();
            clearReportFiles();
        };
    }, []);
    const names = [
        'revenue',
        'settlement',
        'activity',
        'expiry',
        'attendance',
        'occupancy',
        'cancellations',
        'popularity',
    ].filter((name) => {
        if (!context) return false;
        if (['revenue', 'settlement'].includes(name))
            return (
                context.role !== 'trainer' &&
                context.permissions.includes('payments.view')
            );
        if (['activity', 'expiry'].includes(name))
            return (
                context.role !== 'trainer' &&
                context.location_scope === 'all' &&
                context.permissions.includes('members.view') &&
                context.permissions.includes('memberships.view')
            );
        return (
            context.permissions.includes('sessions.view') &&
            context.permissions.includes('attendance.view')
        );
    });
    function change(key: string, value: string) {
        setData(null);
        setVisiblePage(0);
        setFilters((old) => {
            const next = { ...old, page: '1' } as Record<string, string>;
            if (key === 'comparison' && value !== 'custom') {
                delete next.compare_from;
                delete next.compare_until;
            }
            if (value) next[key] = value;
            else delete next[key];
            return next;
        });
    }
    async function exportFile(format: 'csv' | 'xlsx') {
        if (busy) return;
        const controller = new AbortController();
        download.current = controller;
        setBusy(true);
        setError(null);
        try {
            await shareReport(
                client,
                `${base}/reports?${query}&format=${format}`,
                format,
                controller.signal,
            );
        } catch (e) {
            if (!controller.signal.aborted) setError(e);
        } finally {
            if (!controller.signal.aborted) setBusy(false);
        }
    }
    function rows(
        values: Record<string, string | number | boolean | null>[],
        paginate = false,
    ) {
        return values
            .slice(
                paginate ? visiblePage * 25 : 0,
                paginate ? (visiblePage + 1) * 25 : values.length,
            )
            .map((row, i) => (
                <View
                    key={i}
                    style={{
                        borderBottomWidth: 1,
                        borderColor: '#ccd7d2',
                        paddingVertical: 12,
                    }}
                >
                    {Object.entries(row).map(([k, v]) => (
                        <Body key={k}>
                            {text(`reportField_${k}`)}:{' '}
                            {display(
                                ['current', 'previous', 'absolute'].includes(
                                    k,
                                ) && typeof row.metric === 'string'
                                    ? row.metric
                                    : k,
                                v,
                            )}
                        </Body>
                    ))}
                </View>
            ));
    }
    return (
        <Screen>
            <Heading>{text('reportType')}</Heading>
            <Button
                label={locale === 'mk' ? 'English' : 'Македонски'}
                onPress={() => setLocale(locale === 'mk' ? 'en' : 'mk')}
            />
            <Button label={t('back')} onPress={exit} />
            <ErrorMessage error={error} />
            {!context ? (
                <Body>{t('loading')}</Body>
            ) : !context.permissions.includes('reports.view') ? (
                <Body>{t('forbidden')}</Body>
            ) : (
                <>
                    <Body>{text('reportReadOnly')}</Body>
                    <View
                        style={{
                            flexDirection: 'row',
                            flexWrap: 'wrap',
                            gap: 6,
                        }}
                    >
                        {names.map((name) => (
                            <Button
                                key={name}
                                label={text(`report_${name}`)}
                                disabled={report === name}
                                onPress={() => {
                                    setData(null);
                                    setReport(name);
                                    setFilters({
                                        comparison: 'previous',
                                        granularity: 'daily',
                                    });
                                    setVisiblePage(0);
                                }}
                            />
                        ))}
                    </View>
                    <Field
                        label={text('reportFrom')}
                        value={from}
                        onChange={setFrom}
                    />
                    <Field
                        label={text('reportUntil')}
                        value={until}
                        onChange={setUntil}
                    />
                    <Button
                        label={text('reportApply')}
                        onPress={() => {
                            setData(null);
                            setFilters((old) => ({
                                ...old,
                                from,
                                until,
                                page: '1',
                            }));
                        }}
                    />
                    <Body>{text('reportGranularity')}</Body>
                    {['daily', 'weekly', 'monthly'].map((key) => (
                        <Button
                            key={key}
                            label={text(`report_${key}`)}
                            disabled={filters.granularity === key}
                            onPress={() => change('granularity', key)}
                        />
                    ))}
                    <Body>{text('reportComparison')}</Body>
                    {['previous', 'custom', 'none'].map((key) => (
                        <Button
                            key={key}
                            label={text(`report_${key}`)}
                            disabled={filters.comparison === key}
                            onPress={() => change('comparison', key)}
                        />
                    ))}
                    {filters.comparison === 'custom' && (
                        <>
                            <Field
                                label={text('reportCompareFrom')}
                                value={compareFrom}
                                onChange={setCompareFrom}
                            />
                            <Field
                                label={text('reportCompareUntil')}
                                value={compareUntil}
                                onChange={setCompareUntil}
                            />
                            <Button
                                label={text('reportApply')}
                                onPress={() => {
                                    setData(null);
                                    setFilters((old) => ({
                                        ...old,
                                        compare_from: compareFrom,
                                        compare_until: compareUntil,
                                    }));
                                }}
                            />
                        </>
                    )}
                    {data &&
                        Object.entries(data.options).map(([key, options]) => (
                            <View key={key}>
                                <Body>{text(`reportField_${key}`)}</Body>
                                <Button
                                    label={text('reportAll')}
                                    onPress={() => change(key, '')}
                                />
                                {options.rows.map((option) => (
                                    <Button
                                        key={option.id}
                                        label={option.label}
                                        disabled={filters[key] === option.id}
                                        onPress={() => change(key, option.id)}
                                    />
                                ))}
                                {options.truncated && (
                                    <Body>{text('reportOptionsLimited')}</Body>
                                )}
                            </View>
                        ))}
                    {(report === 'settlement'
                        ? [
                              [
                                  'state',
                                  [
                                      'active',
                                      'upcoming',
                                      'expired',
                                      'cancelled',
                                  ],
                              ],
                              [
                                  'payment_state',
                                  [
                                      'unpaid',
                                      'partially_paid',
                                      'paid',
                                      'overpaid',
                                  ],
                              ],
                          ]
                        : report === 'expiry'
                          ? [['expiry', ['expired', '7', '14', '30', 'later']]]
                          : report === 'popularity'
                            ? [
                                  ['rank', ['present', 'reserved', 'waiting']],
                                  ['top', ['10', '25', '50']],
                              ]
                            : []
                    ).map(([key, values]) => (
                        <View key={String(key)}>
                            <Body>
                                {text(
                                    key === 'rank'
                                        ? 'reportRank'
                                        : key === 'top'
                                          ? 'reportTop'
                                          : `reportField_${key}`,
                                )}
                            </Body>
                            <Button
                                label={text('reportAll')}
                                onPress={() => change(String(key), '')}
                            />
                            {(values as string[]).map((value) => (
                                <Button
                                    key={value}
                                    label={
                                        key === 'top'
                                            ? value
                                            : text(
                                                  `${key === 'rank' ? 'reportField_' : 'reportState_'}${value}`,
                                              )
                                    }
                                    disabled={filters[String(key)] === value}
                                    onPress={() => change(String(key), value)}
                                />
                            ))}
                        </View>
                    ))}
                    <Button
                        label={t('refresh')}
                        onPress={() => {
                            setData(null);
                            setRevision((v) => v + 1);
                        }}
                    />
                    <Button
                        label={text('reportReset')}
                        onPress={() => {
                            setData(null);
                            setFilters({
                                comparison: 'previous',
                                granularity: 'daily',
                            });
                        }}
                    />
                    <Button
                        label={text(
                            filters.view === 'details'
                                ? 'reportSummary'
                                : 'reportDetails',
                        )}
                        onPress={() =>
                            change(
                                'view',
                                filters.view === 'details'
                                    ? 'summary'
                                    : 'details',
                            )
                        }
                    />
                    <Body>{text('reportPresets')}</Body>
                    {(['month', 'week', 'year'] as const).map((preset) => (
                        <Button
                            key={preset}
                            label={text(`reportPreset_${preset}`)}
                            disabled={!data?.meta.today}
                            onPress={() => {
                                if (!data) return;
                                const dates = reportPreset(
                                    data.meta.today,
                                    preset,
                                );
                                setFrom(dates.from);
                                setUntil(dates.until);
                                setFilters((old) => ({
                                    ...old,
                                    ...dates,
                                    page: '1',
                                }));
                                setData(null);
                                setVisiblePage(0);
                            }}
                        />
                    ))}
                    <Body>{text('reportLimits')}</Body>
                    <Button
                        label={text('reportCsv')}
                        disabled={busy || !data}
                        onPress={() => void exportFile('csv')}
                    />
                    <Button
                        label={text('reportXlsx')}
                        disabled={busy || !data}
                        onPress={() => void exportFile('xlsx')}
                    />
                    {busy && <Body>{text('reportPreparing')}</Body>}
                    {!data && !error && (
                        <Body>
                            {filters.comparison === 'custom' &&
                            (!filters.compare_from || !filters.compare_until)
                                ? text('reportSelectComparison')
                                : t('loading')}
                        </Body>
                    )}
                    {data && (
                        <>
                            <Heading>{text(`report_${report}`)}</Heading>
                            <Body>
                                {display('from', data.meta.from)} —{' '}
                                {display('until', data.meta.until)} ·{' '}
                                {data.meta.timezone}
                            </Body>
                            <Body>
                                {text('reportAsOf')}:{' '}
                                {display('as_of', data.meta.as_of)}
                            </Body>
                            <Body>
                                {text(`reportBasis_${data.meta.basis}`)}
                            </Body>
                            <Body>{text(`reportDefinition_${report}`)}</Body>
                            <Body>
                                {text('reportPeriodDays')}:{' '}
                                {reportDayCount(
                                    data.meta.from,
                                    data.meta.until,
                                )}
                            </Body>
                            {Object.entries(data.totals).map(([k, v]) => (
                                <Body key={k}>
                                    {text(`reportField_${k}`)}: {display(k, v)}
                                </Body>
                            ))}
                            <Heading>{text('reportChart')}</Heading>
                            {data.rows.slice(0, 60).map((row, i) => {
                                const key = reportChartMetric(report);
                                const n = Number(row[key] ?? 0);
                                const max = Math.max(
                                    1,
                                    ...data.rows.map((r) =>
                                        Math.abs(Number(r[key] ?? 0)),
                                    ),
                                );
                                return (
                                    <View
                                        key={i}
                                        accessible
                                        accessibilityLabel={`${display('period' in row ? 'period' : 'payment_state' in row ? 'payment_state' : 'expiry' in row ? 'expiry' : 'status', row.period ?? row.payment_state ?? row.expiry ?? row.status ?? '')} ${text(`reportField_${key}`)} ${display(key, row[key] ?? null)}`}
                                    >
                                        <Body>
                                            {display(
                                                'period' in row
                                                    ? 'period'
                                                    : 'payment_state' in row
                                                      ? 'payment_state'
                                                      : 'expiry' in row
                                                        ? 'expiry'
                                                        : 'status',
                                                row.period ??
                                                    row.payment_state ??
                                                    row.expiry ??
                                                    row.status ??
                                                    '',
                                            )}
                                            : {display(key, row[key] ?? null)}
                                        </Body>
                                        <View
                                            style={{
                                                height:
                                                    row[key] === null ? 0 : 10,
                                                width: `${(Math.abs(n) / max) * 100}%`,
                                                backgroundColor: '#28564f',
                                            }}
                                        />
                                    </View>
                                );
                            })}
                            <Body>{text('reportChartLimit')}</Body>
                            <Heading>{text('reportTable')}</Heading>
                            {data.rows.length ? (
                                rows(data.rows, true)
                            ) : (
                                <Body>{text('reportEmpty')}</Body>
                            )}
                            {data.rows.length > 25 && (
                                <>
                                    <Button
                                        label={t('previous')}
                                        disabled={visiblePage === 0}
                                        onPress={() =>
                                            setVisiblePage((v) => v - 1)
                                        }
                                    />
                                    <Button
                                        label={t('next')}
                                        disabled={
                                            (visiblePage + 1) * 25 >=
                                            data.rows.length
                                        }
                                        onPress={() =>
                                            setVisiblePage((v) => v + 1)
                                        }
                                    />
                                </>
                            )}
                            {data.pagination && (
                                <>
                                    <Body>
                                        {data.pagination.page}/
                                        {data.pagination.last_page} ·{' '}
                                        {data.pagination.total}
                                    </Body>
                                    <Button
                                        label={t('previous')}
                                        disabled={data.pagination.page === 1}
                                        onPress={() =>
                                            change(
                                                'page',
                                                String(
                                                    data.pagination!.page - 1,
                                                ),
                                            )
                                        }
                                    />
                                    <Button
                                        label={t('next')}
                                        disabled={
                                            data.pagination.page >=
                                            data.pagination.last_page
                                        }
                                        onPress={() =>
                                            change(
                                                'page',
                                                String(
                                                    data.pagination!.page + 1,
                                                ),
                                            )
                                        }
                                    />
                                </>
                            )}
                            {data.dimensions &&
                                Object.entries(data.dimensions).map(
                                    ([key, d]) => (
                                        <View key={key}>
                                            <Heading>
                                                {text(`reportDimension_${key}`)}
                                            </Heading>
                                            {rows(d.rows)}
                                            {d.truncated && (
                                                <Body>
                                                    {text(
                                                        'reportRankingLimited',
                                                    )}
                                                </Body>
                                            )}
                                        </View>
                                    ),
                                )}
                            {data.comparison ? (
                                <>
                                    <Heading>
                                        {text('reportComparison')} ·{' '}
                                        {text('reportPeriodDays')}:{' '}
                                        {reportDayCount(
                                            data.comparison.from,
                                            data.comparison.until,
                                        )}
                                    </Heading>
                                    <Body>
                                        {display('from', data.comparison.from)}{' '}
                                        —{' '}
                                        {display(
                                            'until',
                                            data.comparison.until,
                                        )}
                                    </Body>
                                    {rows(
                                        Object.entries(
                                            data.comparison.changes,
                                        ).map(([metric, c]) => ({
                                            metric,
                                            ...c,
                                        })),
                                    )}
                                </>
                            ) : (
                                <Body>
                                    {text(
                                        filters.comparison === 'none'
                                            ? 'reportNoComparison'
                                            : 'reportNoHistoricalSnapshot',
                                    )}
                                </Body>
                            )}
                        </>
                    )}
                </>
            )}
        </Screen>
    );
}
