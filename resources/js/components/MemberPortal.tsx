import { useEffect, useState, type FormEvent } from 'react';
import { useNavigate } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import { api, ApiError, errorMessage, type Page } from '../lib/api';
import { LanguagePicker } from './LanguagePicker';
import { Inbox } from './Notifications';
type Studio = { id: string; name: string; timezone: string };
type SelfProfile = {
    id: string;
    first_name: string;
    last_name: string;
    email: string | null;
    phone: string | null;
    preferred_locale: string;
};
type Session = {
    id: string;
    group: { id: string; name: string };
    location: { name: string };
    trainer: { name: string };
    starts_at: string;
    ends_at: string;
    timezone: string;
    status: string;
    available_spots: number;
    can_reserve: boolean;
    can_join_waiting: boolean;
    attendance_status: string;
    reservation: { id: string; status: string; can_cancel: boolean } | null;
    waiting_entry: { id: string; status: string; can_cancel: boolean } | null;
};
type Group = {
    id: string;
    name: string;
    description: string | null;
    location: { name: string };
    trainer: { name: string } | null;
    timezone: string;
    schedule: {
        weekday: number;
        start_minute: number;
        end_minute: number;
        effective_from: string;
        effective_until: string | null;
    }[];
};
type Waiting = {
    id: string;
    status: string;
    session_id: string;
    session_name: string;
    starts_at: string;
    timezone: string;
    can_cancel: boolean;
};
type Attendance = {
    id: string;
    status: string;
    session: { name: string; starts_at: string; timezone: string };
};
type Membership = {
    id: string;
    plan_name: string;
    starts_on: string;
    ends_on: string;
    status: string;
    currency: string;
    agreed_price_minor: number;
    net_paid_minor: number;
    balance_minor: number;
};
type Payment = {
    id: string;
    amount_minor: number;
    currency: string;
    paid_at: string;
    status: string;
    reversal: { amount_minor: number; created_at: string } | null;
};
type Section =
    | 'dashboard'
    | 'groups'
    | 'sessions'
    | 'waiting'
    | 'attendance'
    | 'memberships'
    | 'payments'
    | 'notifications'
    | 'profile';
export function MemberPortal() {
    const { t } = useTranslation();
    const navigate = useNavigate();
    const [studios, setStudios] = useState<Page<Studio> | null>(null);
    const [page, setPage] = useState(1);
    const [selected, setSelected] = useState('');
    const [error, setError] = useState('');
    const [switching, setSwitching] = useState(false);
    useEffect(() => {
        const c = new AbortController();
        api.get<Page<Studio>>(
            `/api/v1/member/organizations?page=${page}`,
            c.signal,
        )
            .then((p) => {
                setStudios(p);
                setSelected(p.data[0]?.id ?? '');
            })
            .catch((e: unknown) => {
                if (c.signal.aborted) return;
                if (e instanceof ApiError && e.status === 401)
                    navigate('/member/login', { replace: true });
                else setError(errorMessage(e));
            });
        return () => c.abort();
    }, [page, navigate]);
    async function change(id: string) {
        setSwitching(true);
        setSelected('');
        setError('');
        try {
            await api.post(`/api/v1/member/organizations/${id}/switch`);
            setSelected(id);
        } catch (e) {
            setError(errorMessage(e));
        } finally {
            setSwitching(false);
        }
    }
    return (
        <main className="workspace-content member-portal">
            <header className="page-heading">
                <h1>{t('self.memberArea')}</h1>
                <LanguagePicker />
                <button
                    onClick={() =>
                        void api
                            .post('/auth/logout')
                            .then(() => navigate('/member/login'))
                            .catch((e: unknown) => setError(errorMessage(e)))
                    }
                >
                    {t('signOut')}
                </button>
            </header>
            {error && <p role="alert">{error}</p>}
            {!studios ? (
                <p role="status">{t('loading')}</p>
            ) : !studios.data.length ? (
                <p>{t('noStudio')}</p>
            ) : (
                <>
                    <label>
                        {t('chooseStudio')}
                        <select
                            value={selected}
                            disabled={switching}
                            onChange={(e) => void change(e.target.value)}
                        >
                            <option value="" disabled>
                                {t('chooseStudio')}
                            </option>
                            {studios.data.map((s) => (
                                <option key={s.id} value={s.id}>
                                    {s.name}
                                </option>
                            ))}
                        </select>
                    </label>
                    <Pager
                        page={page}
                        last={studios.meta.last_page}
                        change={(p) => {
                            setStudios(null);
                            setSelected('');
                            setPage(p);
                        }}
                    />
                    {selected && (
                        <MemberWorkspace
                            key={selected}
                            organization={selected}
                        />
                    )}
                </>
            )}
        </main>
    );
}
function Pager({
    page,
    last,
    change,
}: {
    page: number;
    last: number;
    change: (p: number) => void;
}) {
    const { t } = useTranslation();
    return last > 1 ? (
        <nav className="actions">
            <button disabled={page === 1} onClick={() => change(page - 1)}>
                {t('previous')}
            </button>
            <span>
                {page} / {last}
            </span>
            <button disabled={page >= last} onClick={() => change(page + 1)}>
                {t('next')}
            </button>
        </nav>
    ) : null;
}
function MemberWorkspace({ organization }: { organization: string }) {
    const { t, i18n } = useTranslation();
    const [section, setSection] = useState<Section>('dashboard');
    const [group, setGroup] = useState('');
    const [detail, setDetail] = useState('');
    const [revision, setRevision] = useState(0);
    const base = `/api/v1/member/organizations/${organization}`;
    const when = (date: string, zone: string) =>
        `${new Intl.DateTimeFormat(i18n.language, { dateStyle: 'medium', timeStyle: 'short', timeZone: zone }).format(new Date(date))} · ${zone}`;
    const status = (value: string) =>
        t(`self.states.${value}`, { defaultValue: value });
    function sessions(id: string) {
        setGroup(id);
        setDetail('');
        setSection('sessions');
    }
    function open(id: string) {
        setDetail(id);
        setSection('sessions');
    }
    return (
        <>
            <nav className="member-nav" aria-label={t('self.memberArea')}>
                {(
                    [
                        'dashboard',
                        'groups',
                        'sessions',
                        'waiting',
                        'attendance',
                        'memberships',
                        'payments',
                        'notifications',
                        'profile',
                    ] as Section[]
                ).map((s) => (
                    <button
                        key={s}
                        aria-current={section === s ? 'page' : undefined}
                        onClick={() => {
                            setSection(s);
                            setGroup('');
                            setDetail('');
                        }}
                    >
                        {t(`self.${s}`)}
                    </button>
                ))}
            </nav>
            <h2>{t(`self.${section}`)}</h2>
            {section === 'notifications' ? (
                <Inbox organization={organization} />
            ) : section === 'profile' ? (
                <Profile base={base} />
            ) : section === 'dashboard' ? (
                <>
                    <Collection<Session>
                        url={`${base}/sessions?relationship=reserved&per_page=3`}
                        title={t('self.nextSessions')}
                        render={(s) => (
                            <>
                                <h3>{s.group.name}</h3>
                                <p>{when(s.starts_at, s.timezone)}</p>
                                <p>{status(s.status)}</p>
                                <button onClick={() => open(s.id)}>
                                    {t('self.details')}
                                </button>
                            </>
                        )}
                    />
                    <Collection<Membership>
                        url={`${base}/memberships?per_page=3`}
                        title={t('self.memberships')}
                        render={(m) => (
                            <>
                                <h3>{m.plan_name}</h3>
                                <p>
                                    {status(m.status)} · {m.starts_on} –{' '}
                                    {m.ends_on}
                                </p>
                            </>
                        )}
                    />
                    <Collection<Waiting>
                        url={`${base}/waiting-list?per_page=3`}
                        title={t('self.waiting')}
                        render={(w) => (
                            <>
                                <h3>{w.session_name}</h3>
                                <p>{status(w.status)}</p>
                                <button onClick={() => open(w.session_id)}>
                                    {t('self.details')}
                                </button>
                            </>
                        )}
                    />
                    <button onClick={() => setSection('groups')}>
                        {t('self.groups')}
                    </button>
                    <Inbox organization={organization} />
                    <Collection<Attendance>
                        url={`${base}/attendance?per_page=3`}
                        title={t('self.attendance')}
                        render={(a) => (
                            <p>
                                {a.session.name} · {status(a.status)}
                            </p>
                        )}
                    />
                    <Collection<Payment>
                        url={`${base}/payments?per_page=3`}
                        title={t('self.payments')}
                        render={(p) => (
                            <p>
                                {amount(
                                    p.amount_minor,
                                    p.currency,
                                    i18n.language,
                                )}{' '}
                                · {status(p.status)}
                            </p>
                        )}
                    />
                </>
            ) : section === 'groups' ? (
                <Collection<Group>
                    url={`${base}/groups`}
                    render={(g) => (
                        <>
                            <h3>{g.name}</h3>
                            <p>{g.description}</p>
                            <p>
                                {g.location.name} · {g.trainer?.name}
                            </p>
                            {g.schedule.map((r, n) => (
                                <p key={n}>
                                    {t(`weekday_${r.weekday}`)}{' '}
                                    {clock(r.start_minute)}–
                                    {clock(r.end_minute)} · {g.timezone} ·{' '}
                                    {r.effective_from} –{' '}
                                    {r.effective_until ?? '∞'}
                                </p>
                            ))}
                            <button onClick={() => sessions(g.id)}>
                                {t('self.availableSessions')}
                            </button>
                        </>
                    )}
                />
            ) : section === 'sessions' ? (
                <>
                    <p>{t('self.sessionHelp')}</p>
                    {detail ? (
                        <SessionDetail
                            key={`${detail}-${revision}`}
                            url={`${base}/sessions/${detail}`}
                            render={(s) => (
                                <SessionCard
                                    s={s}
                                    base={base}
                                    when={when}
                                    status={status}
                                    refresh={() => setRevision(revision + 1)}
                                />
                            )}
                        />
                    ) : (
                        <Collection<Session>
                            key={group}
                            url={`${base}/sessions?${group ? `group_id=${group}` : 'relationship=reserved'}`}
                            render={(s) => (
                                <>
                                    <h3>{s.group.name}</h3>
                                    <p>{when(s.starts_at, s.timezone)}</p>
                                    <p>
                                        {status(s.status)} ·{' '}
                                        {t('self.freeSpots', {
                                            count: s.available_spots,
                                        })}
                                    </p>
                                    <button onClick={() => open(s.id)}>
                                        {t('self.details')}
                                    </button>
                                </>
                            )}
                        />
                    )}
                    <button
                        onClick={() => {
                            setDetail('');
                            setGroup('');
                            setSection('groups');
                        }}
                    >
                        {t('self.groups')}
                    </button>
                </>
            ) : section === 'waiting' ? (
                <Collection<Waiting>
                    url={`${base}/waiting-list`}
                    render={(w) => (
                        <>
                            <h3>{w.session_name}</h3>
                            <p>
                                {when(w.starts_at, w.timezone)} ·{' '}
                                {status(w.status)}
                            </p>
                            <button onClick={() => open(w.session_id)}>
                                {t('self.details')}
                            </button>
                        </>
                    )}
                />
            ) : section === 'attendance' ? (
                <Collection<Attendance>
                    url={`${base}/attendance`}
                    render={(a) => (
                        <>
                            <h3>{a.session.name}</h3>
                            <p>
                                {when(a.session.starts_at, a.session.timezone)}{' '}
                                · {status(a.status)}
                            </p>
                        </>
                    )}
                />
            ) : section === 'memberships' ? (
                <Collection<Membership>
                    url={`${base}/memberships`}
                    render={(m) => (
                        <>
                            <h3>{m.plan_name}</h3>
                            <p>
                                {m.starts_on} – {m.ends_on} · {status(m.status)}
                            </p>
                            <p>
                                {t('self.price')}:{' '}
                                {amount(
                                    m.agreed_price_minor,
                                    m.currency,
                                    i18n.language,
                                )}
                            </p>
                            <p>
                                {t('self.paid')}:{' '}
                                {amount(
                                    m.net_paid_minor,
                                    m.currency,
                                    i18n.language,
                                )}
                            </p>
                            <p>
                                {t('self.balance')}:{' '}
                                {amount(
                                    m.balance_minor,
                                    m.currency,
                                    i18n.language,
                                )}
                            </p>
                        </>
                    )}
                />
            ) : (
                <Collection<Payment>
                    url={`${base}/payments`}
                    render={(p) => (
                        <>
                            <h3>
                                {amount(
                                    p.amount_minor,
                                    p.currency,
                                    i18n.language,
                                )}
                            </h3>
                            <p>
                                {new Date(p.paid_at).toLocaleDateString(
                                    i18n.language,
                                )}{' '}
                                · {status(p.status)}
                            </p>
                            {p.reversal && (
                                <p>
                                    {t('self.reversal')}:{' '}
                                    {amount(
                                        p.reversal.amount_minor,
                                        p.currency,
                                        i18n.language,
                                    )}{' '}
                                    ·{' '}
                                    {new Date(
                                        p.reversal.created_at,
                                    ).toLocaleDateString(i18n.language)}
                                </p>
                            )}
                        </>
                    )}
                />
            )}
        </>
    );
}
function amount(minor: number, currency: string, locale: string) {
    return new Intl.NumberFormat(locale, {
        style: 'currency',
        currency,
    }).format(minor / 100);
}
function clock(minute: number) {
    return `${String(Math.floor(minute / 60)).padStart(2, '0')}:${String(minute % 60).padStart(2, '0')}`;
}
function Collection<T extends { id: string }>(props: {
    url: string;
    title?: string;
    render: (row: T) => React.ReactNode;
}) {
    return <CollectionPage key={props.url} {...props} />;
}
function CollectionPage<T extends { id: string }>({
    url,
    title,
    render,
}: {
    url: string;
    title?: string;
    render: (row: T) => React.ReactNode;
}) {
    const { t } = useTranslation();
    const [page, setPage] = useState(1);
    const [result, setResult] = useState<Page<T> | null>(null);
    const [error, setError] = useState('');
    useEffect(() => {
        const c = new AbortController();
        api.get<Page<T>>(
            `${url}${url.includes('?') ? '&' : '?'}page=${page}`,
            c.signal,
        )
            .then(setResult)
            .catch((e: unknown) => {
                if (!c.signal.aborted) setError(errorMessage(e));
            });
        return () => c.abort();
    }, [url, page]);
    return (
        <section>
            {title && <h3>{title}</h3>}
            {error ? (
                <p role="alert">{error}</p>
            ) : !result ? (
                <p role="status">{t('loading')}</p>
            ) : (
                <>
                    {!result.data.length && <p>{t('self.empty')}</p>}
                    <div className="member-cards">
                        {result.data.map((row) => (
                            <article className="member-card" key={row.id}>
                                {render(row)}
                            </article>
                        ))}
                    </div>
                    <Pager
                        page={page}
                        last={result.meta.last_page}
                        change={(p) => {
                            setResult(null);
                            setError('');
                            setPage(p);
                        }}
                    />
                </>
            )}
        </section>
    );
}
function SessionDetail({
    url,
    render,
}: {
    url: string;
    render: (s: Session) => React.ReactNode;
}) {
    const { t } = useTranslation();
    const [s, setS] = useState<Session | null>(null);
    const [error, setError] = useState('');
    useEffect(() => {
        const c = new AbortController();
        api.get<{ data: Session }>(url, c.signal)
            .then((p) => setS(p.data))
            .catch((e: unknown) => {
                if (!c.signal.aborted) setError(errorMessage(e));
            });
        return () => c.abort();
    }, [url]);
    return error ? (
        <p role="alert">{error}</p>
    ) : s ? (
        <>{render(s)}</>
    ) : (
        <p role="status">{t('loading')}</p>
    );
}
function SessionCard({
    s,
    base,
    when,
    status,
    refresh,
}: {
    s: Session;
    base: string;
    when: (d: string, z: string) => string;
    status: (s: string) => string;
    refresh: () => void;
}) {
    const { t } = useTranslation();
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    async function act(path: string, data: unknown = {}) {
        setBusy(true);
        setError('');
        try {
            await api.post(`${base}/${path}`, data);
            refresh();
        } catch (e) {
            setError(errorMessage(e));
        } finally {
            setBusy(false);
        }
    }
    return (
        <article className="member-card">
            <h3>{s.group.name}</h3>
            <p>
                {when(s.starts_at, s.timezone)} – {when(s.ends_at, s.timezone)}
            </p>
            <p>
                {s.location.name} · {s.trainer.name}
            </p>
            <p>
                {status(s.status)} ·{' '}
                {t('self.freeSpots', { count: s.available_spots })}
            </p>
            <p>
                {t('self.attendance')}: {status(s.attendance_status)}
            </p>
            {s.reservation && (
                <p>
                    {t('self.reservation')}: {status(s.reservation.status)}
                </p>
            )}
            {s.waiting_entry && (
                <p>
                    {t('self.waiting')}: {status(s.waiting_entry.status)}
                </p>
            )}
            {error && <p role="alert">{error}</p>}
            <div className="actions">
                {s.reservation?.can_cancel && (
                    <button
                        disabled={busy}
                        onClick={() =>
                            void act(`reservations/${s.reservation!.id}/cancel`)
                        }
                    >
                        {t('self.cancelReservation')}
                    </button>
                )}
                {s.waiting_entry?.can_cancel && (
                    <button
                        disabled={busy}
                        onClick={() =>
                            void act(
                                `waiting-list/${s.waiting_entry!.id}/cancel`,
                            )
                        }
                    >
                        {t('self.leaveWaiting')}
                    </button>
                )}
                {s.reservation?.status !== 'active' &&
                    s.waiting_entry?.status !== 'waiting' &&
                    s.can_reserve &&
                    (s.available_spots > 0 ? (
                        <button
                            disabled={busy}
                            onClick={() =>
                                void act(`sessions/${s.id}/reservations`)
                            }
                        >
                            {t('self.reserve')}
                        </button>
                    ) : s.can_join_waiting ? (
                        <button
                            disabled={busy}
                            onClick={() =>
                                void act(`sessions/${s.id}/reservations`, {
                                    allow_waiting: true,
                                })
                            }
                        >
                            {t('self.joinWaiting')}
                        </button>
                    ) : null)}
            </div>
        </article>
    );
}
function Profile({ base }: { base: string }) {
    const { t } = useTranslation();
    const navigate = useNavigate();
    const [profile, setProfile] = useState<SelfProfile | null>(null);
    const [message, setMessage] = useState('');
    const [error, setError] = useState('');
    const [busy, setBusy] = useState(false);
    useEffect(() => {
        const c = new AbortController();
        api.get<{ data: SelfProfile }>(`${base}/profile`, c.signal)
            .then((p) => setProfile(p.data))
            .catch((e: unknown) => {
                if (!c.signal.aborted) setError(errorMessage(e));
            });
        return () => c.abort();
    }, [base]);
    async function submit(e: FormEvent<HTMLFormElement>, password = false) {
        e.preventDefault();
        const data = Object.fromEntries(new FormData(e.currentTarget));
        setBusy(true);
        setError('');
        setMessage('');
        try {
            if (password) {
                await api.post('/api/v1/member/password', data);
                navigate('/member/login');
            } else {
                await api.patch(`${base}/profile`, data);
                setMessage(t('self.saved'));
            }
        } catch (err) {
            setError(errorMessage(err));
        } finally {
            setBusy(false);
        }
    }
    return (
        <>
            {error && <p role="alert">{error}</p>}
            {message && <p role="status">{message}</p>}
            {profile && (
                <form className="member-card" onSubmit={(e) => void submit(e)}>
                    {(
                        ['first_name', 'last_name', 'email', 'phone'] as const
                    ).map((field) => (
                        <label key={field}>
                            {t(`self.${field}`)}
                            <input
                                name={field}
                                type={
                                    field === 'email'
                                        ? 'email'
                                        : field === 'phone'
                                          ? 'tel'
                                          : 'text'
                                }
                                defaultValue={profile[field] ?? ''}
                                required={
                                    field === 'first_name' ||
                                    field === 'last_name'
                                }
                                maxLength={
                                    field === 'email'
                                        ? 254
                                        : field === 'phone'
                                          ? 32
                                          : 100
                                }
                            />
                        </label>
                    ))}
                    <label>
                        {t('self.locale')}
                        <select
                            name="preferred_locale"
                            defaultValue={profile.preferred_locale}
                        >
                            <option value="mk">Македонски</option>
                            <option value="en">English</option>
                        </select>
                    </label>
                    <p>{t('self.contactHelp')}</p>
                    <button disabled={busy}>{t('save')}</button>
                </form>
            )}
            <form
                className="member-card"
                onSubmit={(e) => void submit(e, true)}
            >
                <h3>{t('self.changePassword')}</h3>
                {(
                    [
                        'current_password',
                        'password',
                        'password_confirmation',
                    ] as const
                ).map((field) => (
                    <label key={field}>
                        {t(`self.${field}`)}
                        <input
                            type="password"
                            name={field}
                            required
                            maxLength={1024}
                            minLength={
                                field === 'current_password' ? undefined : 12
                            }
                            autoComplete={
                                field === 'current_password'
                                    ? 'current-password'
                                    : 'new-password'
                            }
                        />
                    </label>
                ))}
                <p>{t('self.revokeHelp')}</p>
                <button disabled={busy}>{t('savePassword')}</button>
            </form>
        </>
    );
}
