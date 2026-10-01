import { useEffect, useState } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import {
    api,
    errorMessage,
    type Organization,
    type TrainingSession,
} from '../lib/api';
import { AttendanceRoster } from './Attendance';

type Session = TrainingSession & {
    phase: string;
    attendance: Record<string, number>;
};
type Meta = { current_page: number; last_page: number; total: number };
type Dashboard = {
    data: Session[];
    summary: Record<string, number>;
    today: string;
    from: string;
    until: string;
    timezone: string;
    as_of: string;
    meta: Meta;
    alerts: { kind: string; severity: string; session_id: string }[];
    alerts_truncated: boolean;
    financial?: Record<string, number>;
    communication: { channel: string; state: string; total: number }[] | null;
};
type Row = {
    id: string;
    member: { id: string; display_name: string };
    member_status: string;
    walk_in: boolean;
    finance_access: boolean;
    membership: {
        status: string;
        payment_status?: string;
        expiring: boolean;
    } | null;
    status: string;
    source: string;
    position: number | null;
    attendance: { status: string } | null;
};
type Roster = { data: Row[]; session: Session; meta: Meta; as_of: string };
type Member = {
    id: string;
    display_name: string;
    status: string;
    email?: string;
    phone?: string;
    finance_access: boolean;
    memberships?: {
        id: string;
        plan_name: string;
        starts_on: string;
        ends_on: string;
        status: string;
        payment_status?: string;
        balance_minor?: number;
        currency?: string;
        expiring: boolean;
    }[];
    memberships_truncated?: boolean;
    profile_available?: boolean;
};
export function Operations({
    organization,
    sessionId,
}: {
    organization: Organization;
    sessionId?: string;
}) {
    const { t } = useTranslation();
    const [params, setParams] = useSearchParams();
    const [revision, setRevision] = useState(0);
    const [result, setResult] = useState<Dashboard | null>(null);
    const [error, setError] = useState('');
    const allowed =
        organization.permissions.includes('sessions.view') &&
        organization.permissions.includes('attendance.view');
    const query = params.toString();
    useEffect(() => {
        if (!allowed || sessionId) return;
        const c = new AbortController();
        api.get<Dashboard>(
            `/api/v1/organizations/${organization.id}/operations?${query}`,
            c.signal,
        )
            .then((data) => {
                if (!c.signal.aborted) {
                    setResult(data);
                    setError('');
                }
            })
            .catch((e) => {
                if (!c.signal.aborted) {
                    setResult(null);
                    setError(errorMessage(e));
                }
            });
        return () => c.abort();
    }, [organization.id, allowed, query, revision, sessionId]);
    if (!allowed) return <p>{t('forbidden')}</p>;
    if (sessionId)
        return (
            <OperationsRoster
                key={`${organization.id}:${sessionId}`}
                organization={organization}
                sessionId={sessionId}
                backQuery={query}
            />
        );
    function filter(key: string, value: string) {
        const next = new URLSearchParams(params);
        next.delete('page');
        if (value) next.set(key, value);
        else next.delete(key);
        setResult(null);
        setParams(next);
    }
    return (
        <section className="operations-workspace">
            <h2>{t('operations')}</h2>
            <p>{t('opsScope')}</p>
            <form
                className="member-filters"
                onSubmit={(e) => {
                    e.preventDefault();
                    const f = new FormData(e.currentTarget);
                    const next = new URLSearchParams();
                    for (const k of [
                        'from',
                        'until',
                        'trainer_id',
                        'location_id',
                        'group_id',
                        'status',
                    ]) {
                        const v = String(f.get(k) ?? '');
                        if (v) next.set(k, v);
                    }
                    setResult(null);
                    setParams(next);
                }}
            >
                <label>
                    {t('opsFrom')}
                    <input
                        type="date"
                        name="from"
                        required
                        defaultValue={params.get('from') ?? result?.today ?? ''}
                        key={`from:${result?.today}`}
                    />
                </label>
                <label>
                    {t('opsUntil')}
                    <input
                        type="date"
                        name="until"
                        required
                        defaultValue={
                            params.get('until') ?? result?.today ?? ''
                        }
                        key={`until:${result?.today}`}
                    />
                </label>
                <label>
                    {t('status')}
                    <select
                        name="status"
                        defaultValue={params.get('status') ?? 'all'}
                    >
                        <option value="all">{t('all')}</option>
                        <option value="scheduled">
                            {t('sessionStatus_scheduled')}
                        </option>
                        <option value="cancelled">
                            {t('sessionStatus_cancelled')}
                        </option>
                    </select>
                </label>
                {(['trainer_id', 'location_id', 'group_id'] as const).map(
                    (k) => (
                        <label key={k}>
                            {t(`ops_${k}`)}
                            <select name={k} defaultValue={params.get(k) ?? ''}>
                                <option value="">{t('all')}</option>
                                {Array.from(
                                    new Map(
                                        (result?.data ?? []).map((s) => {
                                            const item =
                                                k === 'trainer_id'
                                                    ? {
                                                          id: s.trainer.id,
                                                          name: s.trainer
                                                              .display_name,
                                                      }
                                                    : k === 'location_id'
                                                      ? s.location
                                                      : s.group;
                                            return [item.id, item];
                                        }),
                                    ).values(),
                                ).map((v) => (
                                    <option value={v.id} key={v.id}>
                                        {v.name}
                                    </option>
                                ))}
                            </select>
                        </label>
                    ),
                )}
                <button>{t('search')}</button>
                <button
                    type="button"
                    onClick={() => {
                        setResult(null);
                        setParams({});
                    }}
                >
                    {t('todaySessions')}
                </button>
            </form>
            <button onClick={() => setRevision((v) => v + 1)}>
                {t('opsRefresh')}
            </button>
            {error && <p role="alert">{error}</p>}
            {!result && !error && <p role="status">{t('loading')}</p>}
            {result && (
                <>
                    <p>
                        {result.timezone} · {t('opsAsOf')}{' '}
                        {new Date(result.as_of).toLocaleTimeString(
                            organization.locale,
                            { timeZone: result.timezone },
                        )}
                    </p>
                    <div className="record-list">
                        {Object.entries(result.summary).map(([key, n]) => (
                            <span key={key}>
                                {t(`opsTotal_${key}`)}: <strong>{n}</strong>
                                {' · '}
                            </span>
                        ))}
                    </div>
                    {!result.data.length && <p>{t('noSessions')}</p>}
                    {result.data.map((s) => (
                        <article className="record-row" key={s.id}>
                            <div>
                                <strong>
                                    {s.local_date} · {s.start_time}–{s.end_time}{' '}
                                    · {s.group.name}
                                </strong>
                                <p>
                                    {s.trainer.display_name} · {s.location.name}{' '}
                                    · {t(`opsPhase_${s.phase}`)}{' '}
                                    {s.is_overridden && t('opsChanged')}
                                </p>
                                <p>
                                    {t('opsOccupancy', {
                                        reserved: s.reserved_count,
                                        capacity: s.capacity,
                                        free: s.available_spots,
                                        waiting: s.waiting_count,
                                    })}
                                </p>
                                <p>{t('opsAttendanceCounts', s.attendance)}</p>
                            </div>
                            <Link to={`/operations/${s.id}?${query}`}>
                                {t('opsRoster')}
                            </Link>
                        </article>
                    ))}
                    <div className="pagination">
                        <button
                            disabled={result.meta.current_page <= 1}
                            onClick={() =>
                                filter(
                                    'page',
                                    String(result.meta.current_page - 1),
                                )
                            }
                        >
                            {t('previous')}
                        </button>
                        <span>
                            {result.meta.current_page} / {result.meta.last_page}{' '}
                            · {result.meta.total}
                        </span>
                        <button
                            disabled={
                                result.meta.current_page >=
                                result.meta.last_page
                            }
                            onClick={() =>
                                filter(
                                    'page',
                                    String(result.meta.current_page + 1),
                                )
                            }
                        >
                            {t('next')}
                        </button>
                    </div>
                    <h3>{t('opsAlerts')}</h3>
                    {Object.entries(result.financial ?? {}).map(
                        ([key, count]) =>
                            count > 0 && (
                                <p key={key}>
                                    <Link
                                        to={
                                            key === 'payment_due'
                                                ? '/payments'
                                                : '/memberships'
                                        }
                                    >
                                        {t(`opsAlert_${key}`)}: {count}
                                    </Link>
                                </p>
                            ),
                    )}
                    {!result.alerts.length && <p>{t('opsNoAlerts')}</p>}
                    {result.alerts.map((a) => (
                        <p key={`${a.session_id}:${a.kind}`}>
                            <Link to={`/operations/${a.session_id}?${query}`}>
                                {t(`opsAlert_${a.kind}`)}
                            </Link>
                        </p>
                    ))}
                    {result.alerts_truncated && <p>{t('opsTruncated')}</p>}
                    {result.communication !== null && (
                        <>
                            <h3>{t('opsCommunication')}</h3>
                            <p>{t('opsDeliveryCaveat')}</p>
                            {result.communication.map((d) => (
                                <p key={`${d.channel}:${d.state}`}>
                                    {t(`channel_${d.channel}`)} ·{' '}
                                    {t(`delivery_${d.state}`, {
                                        defaultValue: t('opsDeliveryOther'),
                                    })}
                                    : {d.total}
                                </p>
                            ))}
                            <Link to="/settings">{t('settings')}</Link>
                        </>
                    )}
                </>
            )}
        </section>
    );
}
function OperationsRoster({
    organization,
    sessionId,
    backQuery,
}: {
    organization: Organization;
    sessionId: string;
    backQuery: string;
}) {
    const { t } = useTranslation();
    const [result, setResult] = useState<Roster | null>(null);
    const [error, setError] = useState('');
    const [revision, setRevision] = useState(0);
    const [page, setPage] = useState(1);
    const [kind, setKind] = useState('reservations');
    const [search, setSearch] = useState('');
    const [memberId, setMemberId] = useState('');
    const [member, setMember] = useState<Member | null>(null);
    const base = `/api/v1/organizations/${organization.id}/operations/sessions/${sessionId}`;
    useEffect(() => {
        const c = new AbortController();
        api.post<Roster>(`${base}/roster`, { page, kind, search }, c.signal)
            .then((data) => {
                if (!c.signal.aborted) {
                    setResult(data);
                    setError('');
                }
            })
            .catch((e) => {
                if (!c.signal.aborted) {
                    setResult(null);
                    setMember(null);
                    setError(errorMessage(e));
                }
            });
        return () => c.abort();
    }, [base, page, kind, search, revision]);
    useEffect(() => {
        if (!memberId) return;
        const c = new AbortController();
        api.get<{ data: Member }>(`${base}/members/${memberId}`, c.signal)
            .then((r) => {
                if (!c.signal.aborted) setMember(r.data);
            })
            .catch((e) => {
                if (!c.signal.aborted) {
                    setMember(null);
                    setError(errorMessage(e));
                }
            });
        return () => c.abort();
    }, [base, memberId, revision]);
    function refresh() {
        setMember(null);
        setRevision((v) => v + 1);
    }
    return (
        <section className="operations-workspace">
            <Link to={`/operations?${backQuery}`}>{t('operations')}</Link>
            <h2>{t('opsRoster')}</h2>
            <button onClick={refresh}>{t('opsRefresh')}</button>
            {error && <p role="alert">{error}</p>}
            <form
                onSubmit={(e) => {
                    e.preventDefault();
                    setPage(1);
                    setResult(null);
                    setSearch(
                        String(
                            new FormData(e.currentTarget).get('search') ?? '',
                        ),
                    );
                }}
                className="member-filters"
            >
                <label>
                    {t('search')}
                    <input name="search" maxLength={100} />
                </label>
                <button>{t('search')}</button>
                <label>
                    {t('opsRoster')}
                    <select
                        value={kind}
                        onChange={(e) => {
                            setKind(e.target.value);
                            setPage(1);
                            setResult(null);
                        }}
                    >
                        <option value="reservations">
                            {t('opsReservations')}
                        </option>
                        {organization.permissions.includes(
                            'waiting_list.view',
                        ) && <option value="waiting">{t('opsWaiting')}</option>}
                    </select>
                </label>
            </form>
            {!result && !error && <p role="status">{t('loading')}</p>}
            {result && (
                <>
                    <h3>
                        {result.session.group.name} ·{' '}
                        {result.session.start_time}
                    </h3>
                    <p>
                        {t('opsOccupancy', {
                            reserved: result.session.reserved_count,
                            capacity: result.session.capacity,
                            free: result.session.available_spots,
                            waiting: result.session.waiting_count,
                        })}
                    </p>
                    <p>{t('opsAttendanceCounts', result.session.attendance)}</p>
                    <p>{t('opsRowsTotal', { count: result.meta.total })}</p>
                    {!result.data.length && <p>{t('noRoster')}</p>}
                    {result.data.map((row) => (
                        <article className="record-row" key={row.id}>
                            <div>
                                <strong>{row.member.display_name}</strong>
                                {row.member_status && (
                                    <p>
                                        {t(`memberStatus_${row.member_status}`)}
                                    </p>
                                )}
                                {row.finance_access && (
                                    <p>
                                        {row.membership
                                            ? t(
                                                  `financeState.${row.membership.status}`,
                                              )
                                            : t('opsNoMembership')}
                                        {row.membership?.expiring &&
                                            ` · ${t('opsExpiring')}`}
                                        {row.membership?.payment_status &&
                                            ` · ${t(`financeState.${row.membership.payment_status}`)}`}
                                    </p>
                                )}
                                <p>
                                    {row.position !== null &&
                                        `#${row.position} · `}
                                    {row.status === 'waiting'
                                        ? t('opsWaiting')
                                        : t(`reservationStatus_${row.status}`)}
                                    {row.walk_in && ` · ${t('opsWalkIn')}`} ·{' '}
                                    {t(
                                        `attendance_${row.attendance?.status ?? 'unmarked'}`,
                                    )}
                                </p>
                            </div>
                            <button
                                onClick={() => {
                                    setMember(null);
                                    setMemberId(row.member.id);
                                    setRevision((v) => v + 1);
                                }}
                            >
                                {t('opsProfile')}
                            </button>
                        </article>
                    ))}
                    <div className="pagination">
                        <button
                            disabled={page === 1}
                            onClick={() => {
                                setResult(null);
                                setPage(page - 1);
                            }}
                        >
                            {t('previous')}
                        </button>
                        <span>
                            {page} / {result.meta.last_page}
                        </span>
                        <button
                            disabled={page >= result.meta.last_page}
                            onClick={() => {
                                setResult(null);
                                setPage(page + 1);
                            }}
                        >
                            {t('next')}
                        </button>
                    </div>
                    <Link to={`/schedule/${sessionId}`}>
                        {t('opsSessionManage')}
                    </Link>
                    {result.session.operational_issues.map((issue) => (
                        <p key={issue}>{t(`groupIssue_${issue}`)}</p>
                    ))}
                    {result.session.operational_issues.length === 0 && (
                        <AttendanceRoster
                            organization={organization}
                            session={result.session}
                            onChanged={refresh}
                        />
                    )}
                </>
            )}
            {memberId && (
                <section
                    aria-label={t('opsProfile')}
                    className="management-form"
                >
                    <button
                        onClick={() => {
                            setMemberId('');
                            setMember(null);
                        }}
                    >
                        {t('close')}
                    </button>
                    {!member ? (
                        <p>{t(error ? 'retry' : 'loading')}</p>
                    ) : (
                        <>
                            <h3>{member.display_name}</h3>
                            {member.profile_available && (
                                <Link to={`/members/${member.id}`}>
                                    {t('opsFullProfile')}
                                </Link>
                            )}
                            <p>{t(`memberStatus_${member.status}`)}</p>
                            {member.email && <p>{member.email}</p>}
                            {member.phone && <p>{member.phone}</p>}
                            <p>{t('opsNoUses')}</p>
                            {!member.finance_access ? (
                                <p>{t('opsFinanceRestricted')}</p>
                            ) : !member.memberships?.length ? (
                                <p>{t('opsNoMembership')}</p>
                            ) : (
                                member.memberships.map((m) => (
                                    <article key={m.id}>
                                        <strong>{m.plan_name}</strong>
                                        <p>
                                            {m.starts_on} – {m.ends_on} ·{' '}
                                            {t(`financeState.${m.status}`)}
                                        </p>
                                        {m.expiring && (
                                            <p>{t('opsExpiring')}</p>
                                        )}
                                        {m.payment_status && (
                                            <p>
                                                {t(
                                                    `financeState.${m.payment_status}`,
                                                )}{' '}
                                                ·{' '}
                                                {m.balance_minor !==
                                                    undefined &&
                                                    `${(m.balance_minor / 100).toFixed(2)} ${m.currency}`}
                                            </p>
                                        )}
                                        <Link to="/memberships">
                                            {t('memberships')}
                                        </Link>
                                        {organization.permissions.includes(
                                            'payments.view',
                                        ) && (
                                            <Link to="/payments">
                                                {t('payments')}
                                            </Link>
                                        )}
                                    </article>
                                ))
                            )}
                            {member.memberships_truncated && (
                                <p>{t('opsTruncated')}</p>
                            )}
                        </>
                    )}
                </section>
            )}
        </section>
    );
}
