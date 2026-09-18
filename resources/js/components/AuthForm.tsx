import { useState, type FormEvent } from 'react';
import { Link, useNavigate, useLocation } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import { api, ApiError } from '../lib/api';
import { LanguagePicker } from './LanguagePicker';
export function AuthForm({
    mode = 'login',
}: {
    mode?: 'login' | 'forgot' | 'reset';
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
                `/auth/${mode === 'login' ? 'login' : mode === 'forgot' ? 'forgot-password' : 'reset-password'}`,
                {
                    ...data,
                    ...(mode === 'reset' ? { token: params.get('token') } : {}),
                },
            );
            if (mode === 'login') navigate('/', { replace: true });
            else setDone(true);
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
                <p className="eyebrow">{t('studioAdmin')}</p>
                <h1>{t(title)}</h1>
                <p className="muted">
                    {t(
                        mode === 'login'
                            ? 'loginDescription'
                            : mode === 'forgot'
                              ? 'forgotDescription'
                              : 'resetDescription',
                    )}
                </p>
                {done ? (
                    <p role="status" className="notice">
                        {t(mode === 'forgot' ? 'resetSent' : 'resetDone')}
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
                        {mode === 'reset' && (
                            <label>
                                {t('confirmPassword')}
                                <input
                                    name="password_confirmation"
                                    type="password"
                                    autoComplete="new-password"
                                    required
                                    minLength={12}
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
                    to={mode === 'login' ? '/forgot-password' : '/login'}
                >
                    {t(mode === 'login' ? 'forgot' : 'backLogin')}
                </Link>
            </main>
            <footer className="auth-footer">GT · {t('secureWorkspace')}</footer>
        </div>
    );
}
