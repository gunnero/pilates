import { useEffect, useState, type FormEvent } from 'react';
import { useTranslation } from 'react-i18next';
import { api, errorMessage } from '../lib/api';

type Item = {
    id: string;
    state: string;
    title_mk: string;
    title_en: string;
    recipients: number;
};
export function Announcements({ organization }: { organization: string }) {
    const { t, i18n } = useTranslation();
    const base = `/api/v1/organizations/${organization}/announcements`;
    const [items, setItems] = useState<Item[]>([]);
    const [error, setError] = useState('');
    const [busy, setBusy] = useState(false);
    const [version, setVersion] = useState(0);
    const [requestId, setRequestId] = useState(() => crypto.randomUUID());
    useEffect(() => {
        const controller = new AbortController();
        void api
            .get<{ data: Item[] }>(base, controller.signal)
            .then((r) => setItems(r.data))
            .catch((e) => {
                if (!controller.signal.aborted) setError(errorMessage(e));
            });
        return () => controller.abort();
    }, [base, version]);
    async function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        const form = event.currentTarget;
        const data = Object.fromEntries(new FormData(form));
        setBusy(true);
        setError('');
        try {
            await api.post(base, { ...data, request_id: requestId });
            setRequestId(crypto.randomUUID());
            form.reset();
            setVersion((v) => v + 1);
        } catch (e) {
            setError(errorMessage(e));
        } finally {
            setBusy(false);
        }
    }
    return (
        <section aria-label={t('communication.announcements_enabled')}>
            <h3>{t('communication.announcements_enabled')}</h3>
            <p>{t('communication.announcementHelp')}</p>
            {error && <p role="alert">{error}</p>}
            <form className="management-form" onSubmit={(e) => void submit(e)}>
                <fieldset disabled={busy}>
                    {(['mk', 'en'] as const).map((locale) => (
                        <div key={locale}>
                            <label>
                                {t('communication.title')} (
                                {locale.toUpperCase()})
                                <input
                                    name={`title_${locale}`}
                                    required
                                    maxLength={160}
                                />
                            </label>
                            <label>
                                {t('communication.message')} (
                                {locale.toUpperCase()})
                                <textarea
                                    name={`message_${locale}`}
                                    required
                                    maxLength={1000}
                                />
                            </label>
                        </div>
                    ))}
                    <button type="submit">{t('communication.publish')}</button>
                </fieldset>
            </form>
            <button disabled={busy} onClick={() => setVersion((v) => v + 1)}>
                {t('refresh')}
            </button>
            <ul>
                {items.map((item) => (
                    <li key={item.id}>
                        <strong>
                            {i18n.language === 'en'
                                ? item.title_en
                                : item.title_mk}
                        </strong>
                        <p>
                            {t(`communication.${item.state}`)} ·{' '}
                            {t('communication.recipients')}: {item.recipients}
                        </p>
                        {item.state !== 'cancelled' && (
                            <button
                                disabled={busy}
                                onClick={() => {
                                    setBusy(true);
                                    void api
                                        .post(`${base}/${item.id}/cancel`)
                                        .then(() => setVersion((v) => v + 1))
                                        .catch((e) => setError(errorMessage(e)))
                                        .finally(() => setBusy(false));
                                }}
                            >
                                {t('cancel')}
                            </button>
                        )}
                    </li>
                ))}
            </ul>
        </section>
    );
}
