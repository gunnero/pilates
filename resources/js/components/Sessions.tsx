import { AttendanceRoster } from './Attendance';
import { Reservations } from './Reservations';
import { useEffect, useState, type FormEvent } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import {
    api,
    errorMessage,
    type Organization,
    type Page,
    type TrainingGroup,
    type TrainingSession,
} from '../lib/api';
import { GroupSelect } from './GroupSelect';

function day(date: string, offset: number) {
    const value = new Date(`${date}T12:00:00Z`);
    value.setUTCDate(value.getUTCDate() + offset);
    return value.toISOString().slice(0, 10);
}
function GroupChoice({
    organization,
    value,
    onChange,
    required = false,
}: {
    organization: Organization;
    value: string;
    onChange: (id: string, group?: TrainingGroup) => void;
    required?: boolean;
}) {
    const { t } = useTranslation();
    const [items, setItems] = useState<TrainingGroup[]>([]);
    const [page, setPage] = useState(1);
    const [last, setLast] = useState(1);
    const [error, setError] = useState('');
    useEffect(() => {
        const c = new AbortController();
        api.post<Page<TrainingGroup>>(
            `/api/v1/organizations/${organization.id}/groups/search`,
            { status: required ? 'active' : 'all', page, per_page: 100 },
            c.signal,
        )
            .then((r) => {
                setItems((prev) =>
                    page === 1 ? r.data : [...prev, ...r.data],
                );
                setLast(r.meta.last_page);
                setError('');
            })
            .catch((e: unknown) => {
                if (!c.signal.aborted) setError(errorMessage(e));
            });
        return () => c.abort();
    }, [organization.id, page, required]);
    return (
        <div>
            <label>
                {t('sessionGroup')}
                <select
                    name="group_id"
                    value={value}
                    required={required}
                    onChange={(e) =>
                        onChange(
                            e.target.value,
                            items.find((g) => g.id === e.target.value),
                        )
                    }
                >
                    <option value="">
                        {t(required ? 'chooseGroup' : 'all')}
                    </option>
                    {items.map((g) => (
                        <option key={g.id} value={g.id}>
                            {g.name}
                        </option>
                    ))}
                </select>
            </label>
            {page < last && (
                <button type="button" onClick={() => setPage(page + 1)}>
                    {t('loadMoreChoices')}
                </button>
            )}
            {error && <p role="alert">{error}</p>}
        </div>
    );
}
export function SessionForm({
    organization,
    session,
    onSaved,
    onCancel,
}: {
    organization: Organization;
    session?: TrainingSession;
    onSaved: (s: TrainingSession) => void;
    onCancel: () => void;
}) {
    const { t } = useTranslation();
    const [group, setGroup] = useState<TrainingGroup>();
    return (
        <div className="management-form">
            <h3>{t(session ? 'editOccurrence' : 'newSession')}</h3>
            {!session && (
                <GroupChoice
                    organization={organization}
                    value={group?.id ?? ''}
                    required
                    onChange={(_, g) => setGroup(g)}
                />
            )}
            {(session || group) && (
                <SessionFields
                    key={session?.id ?? group?.id}
                    organization={organization}
                    session={session}
                    group={group}
                    onSaved={onSaved}
                    onCancel={onCancel}
                />
            )}{' '}
            {!session && !group && (
                <button type="button" onClick={onCancel}>
                    {t('cancel')}
                </button>
            )}
        </div>
    );
}
function SessionFields({
    organization,
    session,
    group,
    onSaved,
    onCancel,
}: {
    organization: Organization;
    session?: TrainingSession;
    group?: TrainingGroup;
    onSaved: (s: TrainingSession) => void;
    onCancel: () => void;
}) {
    const { t } = useTranslation();
    const [location, setLocation] = useState(
        session?.location.id ?? group?.location.id ?? '',
    );
    const [trainer, setTrainer] = useState(
        session?.trainer.id ?? group?.primary_trainer?.id ?? '',
    );
    const [error, setError] = useState('');
    const [busy, setBusy] = useState(false);
    const timezone = session?.timezone ?? organization.timezone;
    async function submit(e: FormEvent<HTMLFormElement>) {
        e.preventDefault();
        const fields = Object.fromEntries(new FormData(e.currentTarget));
        setBusy(true);
        setError('');
        const data = {
            local_date: fields.local_date,
            start_time: fields.start_time,
            end_time: fields.end_time,
            capacity: Number(fields.capacity),
            location_id: location,
            trainer_id: trainer,
            ...(!session ? { group_id: group?.id } : {}),
        };
        try {
            const base = `/api/v1/organizations/${organization.id}/sessions`;
            const r = session
                ? await api.patch<{ data: TrainingSession }>(
                      `${base}/${session.id}`,
                      data,
                  )
                : await api.post<{ data: TrainingSession }>(base, data);
            onSaved(r.data);
        } catch (err) {
            setError(errorMessage(err));
        } finally {
            setBusy(false);
        }
    }
    return (
        <form onSubmit={(e) => void submit(e)}>
            {error && (
                <p role="alert" className="error">
                    {error}
                </p>
            )}
            <p>{t('localTimezone', { timezone })}</p>
            <p className="muted">{t('occurrenceHint')}</p>
            <fieldset disabled={busy}>
                <label>
                    {t('sessionDate')}
                    <input
                        type="date"
                        name="local_date"
                        required
                        defaultValue={session?.local_date}
                    />
                </label>
                <label>
                    {t('startTime')}
                    <input
                        type="time"
                        name="start_time"
                        required
                        defaultValue={session?.start_time ?? '18:00'}
                    />
                </label>
                <label>
                    {t('endTime')}
                    <input
                        type="time"
                        name="end_time"
                        required
                        defaultValue={session?.end_time ?? '19:00'}
                    />
                </label>
                <label>
                    {t('groupCapacity')}
                    <input
                        type="number"
                        name="capacity"
                        required
                        min={1}
                        max={100}
                        step={1}
                        defaultValue={
                            session?.capacity ?? group?.capacity ?? 12
                        }
                    />
                </label>
                {organization.permissions.includes('locations.view') ? (
                    <GroupSelect
                        organizationId={organization.id}
                        kind="locations"
                        name="location_id"
                        label={t('location')}
                        value={location}
                        onChange={(v) => {
                            setLocation(v);
                            setTrainer('');
                        }}
                        current={session?.location ?? group?.location}
                        required
                    />
                ) : (
                    <p>{session?.location.name ?? group?.location.name}</p>
                )}
                {organization.permissions.includes('trainers.view') &&
                location ? (
                    <GroupSelect
                        key={location}
                        organizationId={organization.id}
                        kind="trainers"
                        name="trainer_id"
                        label={t('primaryTrainer')}
                        value={trainer}
                        onChange={setTrainer}
                        locationId={location}
                        required
                        current={
                            session
                                ? {
                                      id: session.trainer.id,
                                      name: session.trainer.display_name,
                                  }
                                : group?.primary_trainer
                                  ? {
                                        id: group.primary_trainer.id,
                                        name: group.primary_trainer
                                            .display_name,
                                    }
                                  : undefined
                        }
                    />
                ) : (
                    <p>
                        {session?.trainer.display_name ??
                            group?.primary_trainer?.display_name ??
                            t('trainerUnassigned')}
                    </p>
                )}
                <div className="form-actions">
                    <button
                        className="primary"
                        disabled={busy || !location || !trainer}
                    >
                        {t(busy ? 'working' : 'save')}
                    </button>
                    <button type="button" onClick={onCancel}>
                        {t('cancel')}
                    </button>
                </div>
            </fieldset>
        </form>
    );
}
export function Sessions({
    organization,
    sessionId,
}: {
    organization: Organization;
    sessionId?: string;
}) {
    const { t } = useTranslation();
    const navigate = useNavigate();
    const initial = new Intl.DateTimeFormat('sv-SE', {
        timeZone: organization.timezone,
    }).format(new Date());
    const [range, setRange] = useState({
        from: initial,
        until: day(initial, 6),
    });
    const [filters, setFilters] = useState({
        location_id: '',
        trainer_id: '',
        group_id: '',
        status: 'all',
    });
    const [page, setPage] = useState(1);
    const [revision, setRevision] = useState(0);
    const [result, setResult] = useState<Page<TrainingSession> | null>(null);
    const [attendanceRevision, setAttendanceRevision] = useState(0);
    const [session, setSession] = useState<TrainingSession | null>(null);
    const [editing, setEditing] = useState(false);
    const [cancelling, setCancelling] = useState(false);
    const [error, setError] = useState('');
    const [notice, setNotice] = useState('');
    const [busy, setBusy] = useState(false);
    const base = `/api/v1/organizations/${organization.id}/sessions`;
    const view = organization.permissions.includes('sessions.view');
    const manage = organization.permissions.includes('sessions.manage');
    const cancel = organization.permissions.includes('sessions.cancel');
    useEffect(() => {
        if (!view) return;
        const c = new AbortController();
        const params = new URLSearchParams({
            ...range,
            page: String(page),
            ...Object.fromEntries(Object.entries(filters).filter(([, v]) => v)),
        });
        const req = sessionId
            ? api
                  .get<{ data: TrainingSession }>(
                      `${base}/${sessionId}`,
                      c.signal,
                  )
                  .then((r) => setSession(r.data))
            : api
                  .get<Page<TrainingSession>>(`${base}?${params}`, c.signal)
                  .then(setResult);
        req.then(() => setError('')).catch((e: unknown) => {
            if (!c.signal.aborted) {
                setError(errorMessage(e));
                setSession(null);
                setResult(null);
            }
        });
        return () => c.abort();
    }, [view, base, sessionId, range, filters, page, revision]);
    function filter(key: keyof typeof filters, value: string) {
        setResult(null);
        setPage(1);
        setFilters({ ...filters, [key]: value });
    }
    async function cancelSession(e: FormEvent<HTMLFormElement>) {
        e.preventDefault();
        if (!session) return;
        const reason = new FormData(e.currentTarget).get('reason');
        setBusy(true);
        setError('');
        try {
            const r = await api.post<{ data: TrainingSession }>(
                `${base}/${session.id}/cancel`,
                { reason: reason || null },
            );
            setSession(r.data);
            setCancelling(false);
        } catch (err) {
            setError(errorMessage(err));
        } finally {
            setBusy(false);
        }
    }
    async function prepare() {
        setBusy(true);
        setNotice('');
        setError('');
        try {
            const r = await api.post<{ data: Record<string, number> }>(
                `/api/v1/organizations/${organization.id}/groups/${filters.group_id}/materialize`,
            );
            setNotice(
                t('sessionsPrepared', {
                    created: r.data.created,
                    existing: r.data.existing,
                    skipped:
                        r.data.invalid +
                        r.data.dst +
                        r.data.conflict +
                        r.data.limit +
                        (r.data.permanent_conflicts ?? 0) +
                        (r.data.permanent_ineligible ?? 0),
                }),
            );
            setRevision((n) => n + 1);
        } catch (err) {
            setError(errorMessage(err));
        } finally {
            setBusy(false);
        }
    }
    function summary(s: TrainingSession) {
        return (
            <>
                <h3>
                    {s.start_time}–{s.end_time} · {s.group.name}
                </h3>
                <p>
                    {s.local_date} · {s.location.name} ·{' '}
                    {s.trainer.display_name}
                </p>
                <p>
                    {t('capacityValue', { count: s.capacity })} ·{' '}
                    <strong>{t(`sessionStatus_${s.status}`)}</strong>
                </p>
                <p className="muted">
                    {t('localTimezone', { timezone: s.timezone })} ·{' '}
                    {t(`sessionSource_${s.source_type}`)}
                    {s.is_overridden ? ` · ${t('occurrenceEdited')}` : ''}
                </p>
                {s.operational_issues.map((issue) => (
                    <p className="operational-issue" key={issue}>
                        {t(`groupIssue_${issue}`)}
                    </p>
                ))}
            </>
        );
    }
    if (!view) return <p role="alert">{t('accessDenied')}</p>;
    return (
        <section className="management-panel session-agenda">
            <div className="section-heading">
                <h2>{t(sessionId ? 'sessionDetail' : 'sessionAgenda')}</h2>
                {sessionId ? (
                    <Link to="/schedule">{t('backToAgenda')}</Link>
                ) : (
                    manage &&
                    organization.permissions.includes('groups.view') &&
                    !editing && (
                        <button onClick={() => setEditing(true)}>
                            {t('newSession')}
                        </button>
                    )
                )}
            </div>
            {error && (
                <p role="alert" className="error">
                    {error}
                </p>
            )}
            {notice && <p role="status">{notice}</p>}
            {editing && manage && (
                <SessionForm
                    organization={organization}
                    session={session ?? undefined}
                    onCancel={() => setEditing(false)}
                    onSaved={(s) => {
                        setSession(s);
                        setEditing(false);
                        if (!sessionId) void navigate(`/schedule/${s.id}`);
                    }}
                />
            )}
            {sessionId ? (
                session ? (
                    <>
                        {summary(session)}
                        <p>
                            {t('bookingCounts', {
                                reserved: session.reserved_count ?? 0,
                                available:
                                    session.available_spots ?? session.capacity,
                                waiting: session.waiting_count ?? 0,
                            })}
                        </p>
                        <AttendanceRoster
                            key={`attendance:${session.id}:${session.status}:${session.reserved_count}`}
                            organization={organization}
                            session={session}
                            onChanged={() => {
                                setAttendanceRevision((v) => v + 1);
                                void api
                                    .get<{ data: TrainingSession }>(
                                        `${base}/${session.id}`,
                                    )
                                    .then((r) => setSession(r.data))
                                    .catch((e) => setError(errorMessage(e)));
                            }}
                        />
                        <Reservations
                            externalRevision={attendanceRevision}
                            key={`${session.id}:${session.capacity}:${session.status}`}
                            organization={organization}
                            sessionId={session.id}
                            onChanged={() => {
                                void api
                                    .get<{ data: TrainingSession }>(
                                        `${base}/${session.id}`,
                                    )
                                    .then((r) => setSession(r.data))
                                    .catch((e) => setError(errorMessage(e)));
                            }}
                        />
                        {session.cancellation_reason && (
                            <p>
                                {t('cancellationReason')}:{' '}
                                {session.cancellation_reason}
                            </p>
                        )}
                        {session.cancellation_kind === 'schedule' && (
                            <p>{t('scheduleReconciled')}</p>
                        )}
                        {session.editable && (
                            <div className="form-actions">
                                {manage && !editing && (
                                    <button
                                        onClick={() => {
                                            setEditing(true);
                                            setCancelling(false);
                                        }}
                                    >
                                        {t('editOccurrence')}
                                    </button>
                                )}
                                {cancel && !cancelling && (
                                    <button
                                        onClick={() => {
                                            setCancelling(true);
                                            setEditing(false);
                                        }}
                                    >
                                        {t('cancelOccurrence')}
                                    </button>
                                )}
                            </div>
                        )}
                        {cancelling && cancel && (
                            <form
                                className="management-form"
                                onSubmit={(e) => void cancelSession(e)}
                            >
                                <p>{t('confirmOccurrenceCancellation')}</p>
                                <p>
                                    {t('bookingCounts', {
                                        reserved: session.reserved_count ?? 0,
                                        available:
                                            session.available_spots ??
                                            session.capacity,
                                        waiting: session.waiting_count ?? 0,
                                    })}
                                </p>
                                <label>
                                    {t('cancellationReason')}
                                    <input name="reason" maxLength={300} />
                                </label>
                                <div className="form-actions">
                                    <button disabled={busy} className="primary">
                                        {t('confirmCancellation')}
                                    </button>
                                    <button
                                        type="button"
                                        onClick={() => setCancelling(false)}
                                    >
                                        {t('keepSession')}
                                    </button>
                                </div>
                            </form>
                        )}
                    </>
                ) : (
                    !error && <p role="status">{t('loading')}</p>
                )
            ) : (
                <>
                    <form
                        key={`${range.from}:${range.until}`}
                        className="member-filters"
                        onSubmit={(e) => {
                            e.preventDefault();
                            const f = new FormData(e.currentTarget);
                            setResult(null);
                            setPage(1);
                            setRange({
                                from: String(f.get('from')),
                                until: String(f.get('until')),
                            });
                        }}
                    >
                        <label>
                            {t('sessionFrom')}
                            <input
                                type="date"
                                name="from"
                                required
                                defaultValue={range.from}
                            />
                        </label>
                        <label>
                            {t('sessionUntil')}
                            <input
                                type="date"
                                name="until"
                                required
                                defaultValue={range.until}
                            />
                        </label>
                        <button>{t('showSessions')}</button>
                    </form>
                    <div className="form-actions">
                        <button
                            onClick={() => {
                                setResult(null);
                                setPage(1);
                                setRange({
                                    from: day(range.from, -7),
                                    until: day(range.until, -7),
                                });
                            }}
                        >
                            {t('previousWeek')}
                        </button>
                        <button
                            onClick={() => {
                                setResult(null);
                                setPage(1);
                                setRange({
                                    from: day(range.from, 7),
                                    until: day(range.until, 7),
                                });
                            }}
                        >
                            {t('nextWeek')}
                        </button>
                    </div>
                    <div className="member-filters session-filters">
                        {organization.permissions.includes(
                            'locations.view',
                        ) && (
                            <GroupSelect
                                organizationId={organization.id}
                                kind="locations"
                                name="location_id"
                                label={t('location')}
                                value={filters.location_id}
                                onChange={(v) => filter('location_id', v)}
                            />
                        )}
                        {organization.permissions.includes('trainers.view') && (
                            <GroupSelect
                                organizationId={organization.id}
                                kind="trainers"
                                name="trainer_id"
                                label={t('primaryTrainer')}
                                value={filters.trainer_id}
                                onChange={(v) => filter('trainer_id', v)}
                            />
                        )}
                        {organization.permissions.includes('groups.view') && (
                            <GroupChoice
                                organization={organization}
                                value={filters.group_id}
                                onChange={(v) => filter('group_id', v)}
                            />
                        )}
                        <label>
                            {t('status')}
                            <select
                                value={filters.status}
                                onChange={(e) =>
                                    filter('status', e.target.value)
                                }
                            >
                                {['all', 'scheduled', 'cancelled'].map((v) => (
                                    <option key={v} value={v}>
                                        {t(
                                            v === 'all'
                                                ? 'allStatuses'
                                                : `sessionStatus_${v}`,
                                        )}
                                    </option>
                                ))}
                            </select>
                        </label>
                    </div>
                    {manage && filters.group_id && (
                        <div className="form-actions">
                            <button
                                disabled={busy}
                                onClick={() => void prepare()}
                            >
                                {t('prepareSessions')}
                            </button>
                            <p className="muted">{t('prepareSessionsHint')}</p>
                        </div>
                    )}
                    {!result && !error && <p role="status">{t('loading')}</p>}
                    {result && (
                        <>
                            <div className="record-list">
                                {!result.data.length && (
                                    <p>{t('noSessions')}</p>
                                )}
                                {result.data.map((s) => (
                                    <article
                                        className={`record-row session-${s.status}`}
                                        key={s.id}
                                    >
                                        <div>{summary(s)}</div>
                                        <Link
                                            to={`/schedule/${s.id}`}
                                            aria-label={`${t('openSession')} ${s.local_date} ${s.start_time} ${s.group.name}`}
                                        >
                                            {t('openSession')}
                                        </Link>
                                    </article>
                                ))}
                            </div>
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
                        </>
                    )}
                </>
            )}
        </section>
    );
}
