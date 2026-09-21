import { useEffect, useState, type FormEvent } from 'react';
import { Link } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import { QRCodeSVG } from 'qrcode.react';
import {
    api,
    errorMessage,
    type Organization,
    type Page,
    type TrainingSession,
} from '../lib/api';

type Mark = {
    id: string;
    status: 'present' | 'absent' | 'no_show';
    method: string;
    checked_in_at: string | null;
};
type Row = {
    member: { id: string; display_name: string };
    reservation_id: string;
    reservation_status: string;
    attendance: Mark | null;
};
type Qr = { token: string; expires_at: string; refresh_after: number };

export function AttendanceToday({
    organization,
}: {
    organization: Organization;
}) {
    const { t } = useTranslation();
    const [result, setResult] = useState<Page<TrainingSession> | null>(null);
    const [error, setError] = useState('');
    const [page, setPage] = useState(1);
    const allowed =
        organization.permissions.includes('attendance.view') &&
        organization.permissions.includes('sessions.view');
    useEffect(() => {
        if (!allowed) return;
        const c = new AbortController();
        api.get<Page<TrainingSession>>(
            `/api/v1/organizations/${organization.id}/attendance/today?page=${page}`,
            c.signal,
        )
            .then(setResult)
            .catch((e: unknown) => {
                if (!c.signal.aborted) setError(errorMessage(e));
            });
        return () => c.abort();
    }, [organization.id, allowed, page]);
    if (!allowed) return <p>{t('forbidden')}</p>;
    return (
        <section>
            <h2>{t('todaySessions')}</h2>
            {error && <p role="alert">{error}</p>}
            {!result && !error && <p role="status">{t('loading')}</p>}
            {result && (
                <>
                    <div className="record-list">
                        {!result.data.length && <p>{t('noSessions')}</p>}
                        {result.data.map((s) => (
                            <article className="record-row" key={s.id}>
                                <div>
                                    <strong>
                                        {s.start_time}–{s.end_time} ·{' '}
                                        {s.group.name}
                                    </strong>
                                    <p>
                                        {s.location.name} ·{' '}
                                        {t(`sessionStatus_${s.status}`)}
                                    </p>
                                </div>
                                <Link to={`/schedule/${s.id}`}>
                                    {t('openSession')}
                                </Link>
                            </article>
                        ))}
                    </div>
                    <div className="pagination">
                        <button
                            disabled={page === 1}
                            onClick={() => setPage(page - 1)}
                        >
                            {t('previous')}
                        </button>
                        <span>
                            {page} / {result.meta.last_page}
                        </span>
                        <button
                            disabled={page >= result.meta.last_page}
                            onClick={() => setPage(page + 1)}
                        >
                            {t('next')}
                        </button>
                    </div>
                </>
            )}
        </section>
    );
}

function RotatingQr({ base }: { base: string }) {
    const { t } = useTranslation();
    const [qr, setQr] = useState<Qr | null>(null);
    const [error, setError] = useState('');
    useEffect(() => {
        const c = new AbortController();
        let refresh: ReturnType<typeof setTimeout>;
        let expiry: ReturnType<typeof setTimeout>;
        async function load() {
            try {
                const r = await api.post<{ data: Qr }>(
                    `${base}/qr`,
                    {},
                    c.signal,
                );
                if (c.signal.aborted) return;
                setQr(r.data);
                setError('');
                clearTimeout(expiry);
                expiry = setTimeout(
                    () => setQr(null),
                    Math.max(0, Date.parse(r.data.expires_at) - Date.now()),
                );
                refresh = setTimeout(
                    () => void load(),
                    r.data.refresh_after * 1000,
                );
            } catch (e) {
                if (!c.signal.aborted) {
                    setQr(null);
                    setError(errorMessage(e));
                }
            }
        }
        void load();
        return () => {
            c.abort();
            clearTimeout(refresh);
            clearTimeout(expiry);
        };
    }, [base]);
    return (
        <div className="attendance-qr">
            {error && <p role="alert">{error}</p>}
            {qr ? (
                <>
                    <QRCodeSVG
                        value={`gt-checkin:${qr.token}`}
                        size={280}
                        marginSize={4}
                        title={t('showQr')}
                    />
                    <p>
                        {t('qrExpires', {
                            time: new Date(qr.expires_at).toLocaleTimeString(),
                        })}
                    </p>
                </>
            ) : (
                !error && <p role="status">{t('loading')}</p>
            )}
            <p>{t('qrHint')}</p>
        </div>
    );
}

export function AttendanceRoster({
    organization,
    session,
    onChanged,
}: {
    organization: Organization;
    session: TrainingSession;
    onChanged?: () => void;
}) {
    const { t } = useTranslation();
    const allowed = organization.permissions.includes('attendance.view');
    const manage = organization.permissions.includes('attendance.manage');
    const correct = organization.permissions.includes('attendance.correct');
    const walk =
        manage &&
        organization.permissions.includes('reservations.manage') &&
        organization.permissions.includes('reservations.view');
    const base = `/api/v1/organizations/${organization.id}/sessions/${session.id}/attendance`;
    const [result, setResult] = useState<Page<Row> | null>(null);
    const [page, setPage] = useState(1);
    const [revision, setRevision] = useState(0);
    const [error, setError] = useState('');
    const [notice, setNotice] = useState('');
    const [busy, setBusy] = useState(false);
    const [editing, setEditing] = useState<string | null>(null);
    const [qr, setQr] = useState(false);
    const [matches, setMatches] = useState<Row['member'][]>([]);
    useEffect(() => {
        if (!allowed) return;
        const c = new AbortController();
        api.get<Page<Row>>(`${base}?page=${page}`, c.signal)
            .then(setResult)
            .catch((e: unknown) => {
                if (!c.signal.aborted) setError(errorMessage(e));
            });
        return () => c.abort();
    }, [allowed, base, page, revision]);
    async function write(path: string, data: unknown) {
        setBusy(true);
        setError('');
        setNotice('');
        try {
            await api.post(base + path, data);
            setRevision((v) => v + 1);
            setEditing(null);
            setMatches([]);
            setNotice(t('attendanceSaved'));
            onChanged?.();
        } catch (e) {
            setError(errorMessage(e));
        } finally {
            setBusy(false);
        }
    }
    async function search(e: FormEvent<HTMLFormElement>) {
        e.preventDefault();
        setMatches([]);
        setError('');
        setBusy(true);
        const search = new FormData(e.currentTarget).get('search');
        try {
            setMatches(
                (
                    await api.post<{ data: Row['member'][] }>(
                        `${base}/member-lookup`,
                        { search },
                    )
                ).data,
            );
        } catch (e) {
            setError(errorMessage(e));
        } finally {
            setBusy(false);
        }
    }
    if (!allowed) return null;
    const ended = Date.now() >= Date.parse(session.ends_at);
    const open = session.status === 'scheduled';
    return (
        <section aria-label={t('attendanceTitle')}>
            <h3>{t('attendanceTitle')}</h3>
            <p className="muted">{t('attendancePolicy')}</p>
            {error && <p role="alert">{error}</p>}
            {notice && <p role="status">{notice}</p>}
            {result && (
                <>
                    <div className="record-list">
                        {!result.data.length && <p>{t('noRoster')}</p>}
                        {result.data.map((row) => (
                            <article
                                className="record-row"
                                key={row.reservation_id}
                            >
                                <div>
                                    <strong>{row.member.display_name}</strong>
                                    <p>
                                        {t(
                                            `attendance_${row.attendance?.status ?? 'unmarked'}`,
                                        )}{' '}
                                        ·{' '}
                                        {t(
                                            `reservationStatus_${row.reservation_status}`,
                                        )}
                                        {row.attendance?.checked_in_at &&
                                            ` · ${new Date(row.attendance.checked_in_at).toLocaleTimeString(organization.locale, { timeZone: session.timezone })}`}
                                    </p>
                                </div>
                                {open &&
                                    row.reservation_status === 'active' && (
                                        <div className="form-actions">
                                            {manage &&
                                                !row.attendance &&
                                                session.attendance_open && (
                                                    <button
                                                        className="primary"
                                                        disabled={busy}
                                                        onClick={() =>
                                                            void write('', {
                                                                member_id:
                                                                    row.member
                                                                        .id,
                                                                status: 'present',
                                                            })
                                                        }
                                                    >
                                                        {t('markPresent')}
                                                    </button>
                                                )}
                                            {manage &&
                                                !row.attendance &&
                                                ended && (
                                                    <>
                                                        <button
                                                            disabled={busy}
                                                            onClick={() =>
                                                                void write('', {
                                                                    member_id:
                                                                        row
                                                                            .member
                                                                            .id,
                                                                    status: 'no_show',
                                                                })
                                                            }
                                                        >
                                                            {t('markNoShow')}
                                                        </button>
                                                        <button
                                                            disabled={busy}
                                                            onClick={() =>
                                                                void write('', {
                                                                    member_id:
                                                                        row
                                                                            .member
                                                                            .id,
                                                                    status: 'absent',
                                                                })
                                                            }
                                                        >
                                                            {t('markAbsent')}
                                                        </button>
                                                    </>
                                                )}
                                            {correct &&
                                                (row.attendance || ended) && (
                                                    <button
                                                        disabled={busy}
                                                        onClick={() =>
                                                            setEditing(
                                                                row.member.id,
                                                            )
                                                        }
                                                    >
                                                        {t('correctAttendance')}
                                                    </button>
                                                )}
                                            {editing === row.member.id && (
                                                <form
                                                    className="management-form"
                                                    onSubmit={(e) => {
                                                        e.preventDefault();
                                                        const f = new FormData(
                                                            e.currentTarget,
                                                        );
                                                        void write('/correct', {
                                                            member_id:
                                                                row.member.id,
                                                            status: f.get(
                                                                'status',
                                                            ),
                                                            reason: f.get(
                                                                'reason',
                                                            ),
                                                        });
                                                    }}
                                                >
                                                    <label>
                                                        {t('status')}
                                                        <select name="status">
                                                            {[
                                                                'present',
                                                                ...(ended
                                                                    ? [
                                                                          'absent',
                                                                          'no_show',
                                                                      ]
                                                                    : []),
                                                            ].map((s) => (
                                                                <option
                                                                    key={s}
                                                                    value={s}
                                                                >
                                                                    {t(
                                                                        `attendance_${s}`,
                                                                    )}
                                                                </option>
                                                            ))}
                                                        </select>
                                                    </label>
                                                    <label>
                                                        {t('correctionReason')}
                                                        <select name="reason">
                                                            {[
                                                                'mistake',
                                                                'late_confirmation',
                                                            ].map((s) => (
                                                                <option
                                                                    key={s}
                                                                    value={s}
                                                                >
                                                                    {t(
                                                                        `reason_${s}`,
                                                                    )}
                                                                </option>
                                                            ))}
                                                        </select>
                                                    </label>
                                                    <button disabled={busy}>
                                                        {t('save')}
                                                    </button>
                                                    <button
                                                        type="button"
                                                        onClick={() =>
                                                            setEditing(null)
                                                        }
                                                    >
                                                        {t('cancel')}
                                                    </button>
                                                </form>
                                            )}
                                        </div>
                                    )}
                            </article>
                        ))}
                    </div>
                    <div className="pagination">
                        <button
                            disabled={page === 1}
                            onClick={() => setPage(page - 1)}
                        >
                            {t('previous')}
                        </button>
                        <span>
                            {page} / {result.meta.last_page}
                        </span>
                        <button
                            disabled={page >= result.meta.last_page}
                            onClick={() => setPage(page + 1)}
                        >
                            {t('next')}
                        </button>
                    </div>
                </>
            )}
            {open && walk && session.attendance_open && (
                <>
                    <form
                        className="member-filters"
                        onSubmit={(e) => void search(e)}
                    >
                        <label>
                            {t('attendanceSearch')}
                            <input
                                name="search"
                                minLength={2}
                                maxLength={100}
                                required
                            />
                        </label>
                        <button disabled={busy}>{t('search')}</button>
                    </form>
                    {matches.map((m) => (
                        <div className="record-row" key={m.id}>
                            <span>{m.display_name}</span>
                            <button
                                disabled={busy}
                                onClick={() =>
                                    void write('/walk-in', { member_id: m.id })
                                }
                            >
                                {t('walkIn')}
                            </button>
                        </div>
                    ))}
                </>
            )}
            {open && manage && session.attendance_open && (
                <>
                    <button onClick={() => setQr((v) => !v)}>
                        {t(qr ? 'hideQr' : 'showQr')}
                    </button>
                    {qr && <RotatingQr base={base} />}
                </>
            )}
        </section>
    );
}
