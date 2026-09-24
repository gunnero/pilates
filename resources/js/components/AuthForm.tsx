import { useState, type FormEvent } from 'react';
import { Link, useNavigate, useLocation } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import { api, ApiError } from '../lib/api';
import { LanguagePicker } from './LanguagePicker';
export function AuthForm({
    mode = 'login',
    member = false,
}: {
    mode?: 'login' | 'forgot' | 'reset' | 'activate';
    member?: boolean;
}) {
    const { t } = useTranslation();
    const navigate = useNavigate();
    const location = useLocation();
    const params = new URLSearchParams(location.hash.slice(1));
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const [done, setDone] = useState(false);
    async function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        setBusy(true);
        setError('');
        const data = Object.fromEntries(new FormData(event.currentTarget));
        try {
            await api.post(
                `/auth/${mode === 'login' ? 'login' : mode === 'forgot' ? 'forgot-password' : mode === 'activate' ? 'activate' : 'reset-password'}`,
                {
                    ...data,
                    ...(mode === 'reset' || mode === 'activate'
                        ? { token: params.get('token') }
                        : {}),
                },
            );
            if (mode === 'login')
                navigate(member ? '/member' : '/', { replace: true });
            else {
                setDone(true);
                if (mode === 'activate' || mode === 'reset')
                    window.history.replaceState(null, '', location.pathname);
            }
        } catch (err) {
            setError(
                err instanceof ApiError
                    ? Object.values(err.errors ?? {})
                          .flat()
                          .join(' ') || err.message
                    : t('error'),
            );
        } finally {
            setBusy(false);
        }
    }
    const title =
        mode === 'login'
            ? 'loginTitle'
            : mode === 'forgot'
              ? 'forgotTitle'
              : mode === 'activate'
                ? 'self.activate'
                : 'resetTitle';
    return (
        <div className="auth-page">
            <header className="auth-header">
                <Link className="brand" to="/login">
                    <span className="brand-mark">GT</span>
                    <span>{t('brand')}</span>
                </Link>
                <LanguagePicker />
            </header>
            <main className="auth-card">
                <p className="eyebrow">
                    {t(member ? 'self.memberArea' : 'studioAdmin')}
                </p>
                <h1>{t(title)}</h1>
                <p className="muted">
                    {t(
                        mode === 'login'
                            ? 'loginDescription'
                            : mode === 'forgot'
                              ? 'forgotDescription'
                              : mode === 'activate'
                                ? 'self.activationHelp'
                                : 'resetDescription',
                    )}
                </p>
                {done ? (
                    <p role="status" className="notice">
                        {t(
                            mode === 'forgot'
                                ? 'resetSent'
                                : mode === 'activate'
                                  ? 'self.activated'
                                  : 'resetDone',
                        )}
                    </p>
                ) : (
                    <form onSubmit={(event) => void submit(event)}>
                        <label>
                            {t('email')}
                            <input
                                name="email"
                                type="email"
                                autoComplete="username"
                                required
                                maxLength={254}
                                defaultValue={params.get('email') ?? ''}
                            />
                        </label>
                        {mode !== 'forgot' && (
                            <label>
                                {t('password')}
                                <input
                                    name="password"
                                    type="password"
                                    autoComplete={
                                        mode === 'login'
                                            ? 'current-password'
                                            : 'new-password'
                                    }
                                    required
                                    minLength={
                                        mode === 'reset' ? 12 : undefined
                                    }
                                    maxLength={1024}
                                />
                            </label>
                        )}
                        {(mode === 'reset' || mode === 'activate') && (
                            <label>
                                {t('confirmPassword')}
                                <input
                                    name="password_confirmation"
                                    type="password"
                                    autoComplete="new-password"
                                    required
                                    minLength={
                                        mode === 'reset' ? 12 : undefined
                                    }
                                    maxLength={1024}
                                />
                            </label>
                        )}
                        {error && (
                            <p className="error" role="alert">
                                {error}
                            </p>
                        )}
                        <button className="primary" disabled={busy}>
                            {t(
                                busy
                                    ? 'working'
                                    : mode === 'login'
                                      ? 'signIn'
                                      : mode === 'forgot'
                                        ? 'sendReset'
                                        : 'savePassword',
                            )}
                        </button>
                    </form>
                )}
                <Link
                    className="text-link"
                    to={
                        mode === 'login'
                            ? member
                                ? '/member/forgot-password'
                                : '/forgot-password'
                            : member
                              ? '/member/login'
                              : '/login'
                    }
                >
                    {t(mode === 'login' ? 'forgot' : 'backLogin')}
                </Link>
            </main>
            <footer className="auth-footer">GT · {t('secureWorkspace')}</footer>
        </div>
    );
}
