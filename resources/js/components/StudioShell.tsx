import { useEffect, useState } from 'react';
import {
    Link,
    Navigate,
    NavLink,
    useLocation,
    useNavigate,
} from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import { api, ApiError, type Organization, type Profile } from '../lib/api';
import { LanguagePicker } from './LanguagePicker';
const sections = [
    'dashboard',
    'members',
    'groups',
    'schedule',
    'attendance',
    'payments',
    'trainers',
    'reports',
    'settings',
] as const;
export function StudioShell() {
    const { t } = useTranslation();
    const [profile, setProfile] = useState<Profile | null>(null);
    const [anonymous, setAnonymous] = useState(false);
    const [error, setError] = useState('');
    const [selected, setSelected] = useState('');
    const [organization, setOrganization] = useState<Organization | null>(null);
    const [tenantError, setTenantError] = useState('');
    const [menu, setMenu] = useState(false);
    const [retry, setRetry] = useState(0);
    const [loggingOut, setLoggingOut] = useState(false);
    const location = useLocation();
    const navigate = useNavigate();
    useEffect(() => {
        const controller = new AbortController();
        api.get<{ data: Profile }>('/api/v1/me', controller.signal)
            .then(({ data }) => {
                setProfile(data);
                setSelected(data.organizations[0]?.id ?? '');
                setError('');
            })
            .catch((err: unknown) => {
                if (controller.signal.aborted) return;
                if (err instanceof ApiError && err.status === 401)
                    setAnonymous(true);
                else setError('error');
            });
        return () => controller.abort();
    }, [retry]);
    useEffect(() => {
        if (!selected) return;
        const controller = new AbortController();
        api.get<{ data: Organization }>(
            `/api/v1/organizations/${selected}/context`,
            controller.signal,
        )
            .then(({ data }) => {
                setOrganization(data);
                setTenantError('');
            })
            .catch((err: unknown) => {
                if (controller.signal.aborted) return;
                if (err instanceof ApiError && err.status === 401)
                    setAnonymous(true);
                else
                    setTenantError(
                        err instanceof ApiError &&
                            [403, 404].includes(err.status)
                            ? 'accessDenied'
                            : 'error',
                    );
            });
        return () => controller.abort();
    }, [selected, retry]);
    async function logout() {
        setLoggingOut(true);
        try {
            await api.post('/auth/logout');
            navigate('/login', { replace: true });
        } catch (err) {
            if (err instanceof ApiError && [401, 419].includes(err.status))
                navigate('/login', { replace: true });
            else setError('error');
        } finally {
            setLoggingOut(false);
        }
    }
    if (anonymous) return <Navigate to="/login" replace />;
    if (!profile)
        return (
            <main className="loading" role="status">
                {t(error || 'loading')}
                {error && (
                    <button onClick={() => setRetry(retry + 1)}>
                        {t('retry')}
                    </button>
                )}
            </main>
        );
    const section =
        location.pathname === '/' ? 'dashboard' : location.pathname.slice(1);
    const known = sections.some((item) => item === section);
    const ready = organization?.id === selected && !tenantError;
    return (
        <div className="studio-layout">
            <a className="skip" href="#content">
                {t('skip')}
            </a>
            <aside className={`sidebar ${menu ? 'is-open' : ''}`}>
                <Link className="brand" to="/">
                    <span className="brand-mark">GT</span>
                    <span>
                        {t('brand')}
                        <small>{t('studioAdmin')}</small>
                    </span>
                </Link>
                <p className="nav-label">{t('workspace')}</p>
                <nav aria-label={t('nav')}>
                    {sections.map((item, index) => (
                        <NavLink
                            key={item}
                            to={item === 'dashboard' ? '/' : `/${item}`}
                            end
                            onClick={() => setMenu(false)}
                        >
                            <span className="nav-number" aria-hidden="true">
                                {String(index + 1).padStart(2, '0')}
                            </span>
                            <span>{t(item)}</span>
                            {index > 0 && (
                                <span
                                    className="nav-dot"
                                    title={t('soon')}
                                    aria-label={t('soon')}
                                />
                            )}
                        </NavLink>
                    ))}
                </nav>
                <div className="sidebar-foot">
                    <span className="avatar" aria-hidden="true">
                        {profile.name.slice(0, 1)}
                    </span>
                    <div>
                        <strong>{profile.name}</strong>
                        <button
                            className="logout"
                            disabled={loggingOut}
                            onClick={() => void logout()}
                        >
                            {t('signOut')}
                        </button>
                    </div>
                </div>
            </aside>
            <div className="main-column">
                <header className="topbar">
                    <button
                        className="menu-button"
                        aria-expanded={menu}
                        onClick={() => setMenu(!menu)}
                    >
                        {t(menu ? 'closeMenu' : 'menu')}
                    </button>
                    <span className="breadcrumb">
                        GT <span>/</span> {t(known ? section : 'notFound')}
                    </span>
                    <LanguagePicker />
                </header>
                <main id="content" className="workspace-content">
                    <div className="page-heading">
                        <div>
                            <p className="eyebrow">{t('studioAdmin')}</p>
                            <h1>{t(known ? section : 'notFound')}</h1>
                        </div>
                        {profile.organizations.length > 0 && (
                            <label className="studio-picker">
                                <span>{t('chooseStudio')}</span>
                                <select
                                    value={selected}
                                    onChange={(event) => {
                                        setSelected(event.target.value);
                                        setOrganization(null);
                                        setTenantError('');
                                    }}
                                >
                                    {profile.organizations.map((item) => (
                                        <option value={item.id} key={item.id}>
                                            {item.name}
                                        </option>
                                    ))}
                                </select>
                            </label>
                        )}
                    </div>
                    {error && (
                        <p role="alert" className="error">
                            {t(error)}
                        </p>
                    )}
                    {!selected ? (
                        <p className="notice">{t('noStudio')}</p>
                    ) : tenantError ? (
                        <div className="notice" role="alert">
                            {t(tenantError)}
                            <button onClick={() => setRetry(retry + 1)}>
                                {t('retry')}
                            </button>
                        </div>
                    ) : !ready ? (
                        <p role="status">{t('loading')}</p>
                    ) : (
                        <section className="welcome-panel">
                            <div className="panel-symbol" aria-hidden="true">
                                GT
                            </div>
                            <p className="eyebrow">{organization.name}</p>
                            <h2>
                                {t(
                                    section === 'dashboard'
                                        ? 'welcome'
                                        : known
                                          ? section
                                          : 'notFound',
                                )}
                            </h2>
                            <p>
                                {t(
                                    section === 'dashboard'
                                        ? 'foundationDescription'
                                        : 'notReady',
                                )}
                            </p>
                            {!known && <Link to="/">{t('home')}</Link>}
                            <div className="panel-status">
                                <span aria-hidden="true" />
                                {t(
                                    section === 'dashboard'
                                        ? 'studioReady'
                                        : 'soon',
                                )}
                            </div>
                        </section>
                    )}
                </main>
                <footer className="workspace-footer">GT · {t('brand')}</footer>
            </div>
        </div>
    );
}
