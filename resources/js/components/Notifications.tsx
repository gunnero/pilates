import { useEffect, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import { api, ApiError, errorMessage, type Page } from '../lib/api';
import { LanguagePicker } from './LanguagePicker';
type Notice = {
    id: string;
    title: string;
    message: string;
    created_at: string;
    read_at: string | null;
    current: boolean;
};
type Preferences = {
    reminders_enabled: boolean;
    expiry_reminders_enabled: boolean;
};
export function Notifications() {
    const { t } = useTranslation();
    const navigate = useNavigate();
    const [contexts, setContexts] = useState<Page<{
        id: string;
        name: string;
    }> | null>(null);
    const [contextPage, setContextPage] = useState(1);
    const [selected, setSelected] = useState('');
    const [error, setError] = useState('');
    useEffect(() => {
        const controller = new AbortController();
        api.get<Page<{ id: string; name: string }>>(
            `/api/v1/notification-contexts?page=${contextPage}`,
            controller.signal,
        )
            .then((data) => {
                setContexts(data);
                setSelected(data.data[0]?.id ?? '');
            })
            .catch((e: unknown) => {
                if (controller.signal.aborted) return;
                if (e instanceof ApiError && e.status === 401)
                    navigate('/login', { replace: true });
                else setError(errorMessage(e));
            });
        return () => controller.abort();
    }, [contextPage, navigate]);
    return (
        <main className="workspace-content">
            <header className="page-heading">
                <h1>{t('notifications')}</h1>
                <LanguagePicker />
            </header>
            <p>
                <Link to="/">{t('home')}</Link> ·{' '}
                <button
                    onClick={() => {
                        void api
                            .post('/auth/logout')
                            .then(() => navigate('/login'))
                            .catch((e: unknown) => setError(errorMessage(e)));
                    }}
                >
                    {t('signOut')}
                </button>
            </p>
            {error && <p role="alert">{error}</p>}
            {!contexts ? (
                <p role="status">{t('loading')}</p>
            ) : !contexts.data.length ? (
                <p>{t('noStudio')}</p>
            ) : (
                <>
                    <label>
                        {t('chooseStudio')}
                        <select
                            value={selected}
                            onChange={(e) => setSelected(e.target.value)}
                        >
                            {contexts.data.map((c) => (
                                <option key={c.id} value={c.id}>
                                    {c.name}
                                </option>
                            ))}
                        </select>
                    </label>
                    {contexts.meta.last_page > 1 && (
                        <nav aria-label={t('chooseStudio')}>
                            <button
                                disabled={contextPage === 1}
                                onClick={() => setContextPage(contextPage - 1)}
                            >
                                {t('previous')}
                            </button>
                            <button
                                disabled={
                                    contextPage === contexts.meta.last_page
                                }
                                onClick={() => setContextPage(contextPage + 1)}
                            >
                                {t('next')}
                            </button>
                        </nav>
                    )}
                    {selected && (
                        <Inbox key={selected} organization={selected} />
                    )}
                </>
            )}
        </main>
    );
}
export function Inbox({ organization }: { organization: string }) {
    const { t, i18n } = useTranslation();
    const base = `/api/v1/organizations/${organization}/notifications`;
    const [page, setPage] = useState(1);
    const [unread, setUnread] = useState(false);
    const [refresh, setRefresh] = useState(0);
    const [result, setResult] = useState<
        (Page<Notice> & { unread_count: number }) | null
    >(null);
    const [prefs, setPrefs] = useState<Preferences | null>(null);
    const [error, setError] = useState('');
    const [busy, setBusy] = useState(false);
    useEffect(() => {
        const controller = new AbortController();
        Promise.all([
            api.get<Page<Notice> & { unread_count: number }>(
                `${base}?page=${page}&per_page=20&unread=${unread ? 1 : 0}`,
                controller.signal,
            ),
            api.get<{ data: Preferences }>(
                `${base}/preferences`,
                controller.signal,
            ),
        ])
            .then(([list, preferences]) => {
                setResult(list);
                setPrefs(preferences.data);
                setError('');
            })
            .catch((e: unknown) => {
                if (!controller.signal.aborted) setError(errorMessage(e));
            });
        return () => controller.abort();
    }, [base, page, unread, refresh, i18n.language]);
    async function act(operation: () => Promise<unknown>) {
        setBusy(true);
        setError('');
        try {
            await operation();
            setResult(null);
            setRefresh((v) => v + 1);
        } catch (e) {
            setError(errorMessage(e));
        } finally {
            setBusy(false);
        }
    }
    const ids = result?.data.filter((n) => !n.read_at).map((n) => n.id) ?? [];
    return (
        <section className="notification-inbox" aria-label={t('notifications')}>
            {error && <p role="alert">{error}</p>}
            <label>
                <input
                    type="checkbox"
                    checked={unread}
                    onChange={(e) => {
                        setResult(null);
                        setUnread(e.target.checked);
                        setPage(1);
                    }}
                />
                {t('notificationUnreadOnly')}
            </label>
            {!result ? (
                <p role="status">{t('loading')}</p>
            ) : (
                <>
                    <p role="status">
                        {t('notificationUnreadCount', {
                            count: result.unread_count,
                        })}
                    </p>
                    <button
                        disabled={busy || !ids.length}
                        onClick={() =>
                            void act(() => api.post(`${base}/read`, { ids }))
                        }
                    >
                        {t('notificationReadPage')}
                    </button>
                    {!result.data.length ? (
                        <p>{t('notificationEmpty')}</p>
                    ) : (
                        <ul className="notification-list">
                            {result.data.map((n) => (
                                <li key={n.id}>
                                    <article>
                                        <h2>
                                            {n.title}{' '}
                                            {!n.read_at && (
                                                <span className="badge">
                                                    {t('notificationUnread')}
                                                </span>
                                            )}
                                        </h2>
                                        <p>{n.message}</p>
                                        <time dateTime={n.created_at}>
                                            {new Date(
                                                n.created_at,
                                            ).toLocaleString(i18n.language)}
                                        </time>
                                        {!n.current && (
                                            <p>{t('notificationHistorical')}</p>
                                        )}
                                        {!n.read_at && (
                                            <p>
                                                <button
                                                    disabled={busy}
                                                    onClick={() =>
                                                        void act(() =>
                                                            api.post(
                                                                `${base}/${n.id}/read`,
                                                            ),
                                                        )
                                                    }
                                                >
                                                    {t('notificationMarkRead')}
                                                </button>
                                            </p>
                                        )}
                                    </article>
                                </li>
                            ))}
                        </ul>
                    )}
                    <nav aria-label={t('notificationPages')}>
                        <button
                            disabled={busy || page <= 1}
                            onClick={() => {
                                setResult(null);
                                setPage(page - 1);
                            }}
                        >
                            {t('previous')}
                        </button>
                        <span>
                            {' '}
                            {page} / {result.meta.last_page}{' '}
                        </span>
                        <button
                            disabled={busy || page >= result.meta.last_page}
                            onClick={() => {
                                setResult(null);
                                setPage(page + 1);
                            }}
                        >
                            {t('next')}
                        </button>
                    </nav>
                </>
            )}
            {prefs && (
                <fieldset disabled={busy}>
                    <legend>{t('notificationPreferences')}</legend>
                    <label>
                        <input
                            type="checkbox"
                            checked={prefs.reminders_enabled}
                            onChange={(e) =>
                                void act(() =>
                                    api.patch(`${base}/preferences`, {
                                        ...prefs,
                                        reminders_enabled: e.target.checked,
                                    }),
                                )
                            }
                        />
                        {t('notificationReminders')}
                    </label>
                    <label>
                        <input
                            type="checkbox"
                            checked={prefs.expiry_reminders_enabled}
                            onChange={(e) =>
                                void act(() =>
                                    api.patch(`${base}/preferences`, {
                                        ...prefs,
                                        expiry_reminders_enabled:
                                            e.target.checked,
                                    }),
                                )
                            }
                        />
                        {t('notificationExpiry')}
                    </label>
                    <p>{t('notificationRequired')}</p>
                </fieldset>
            )}
        </section>
    );
}
