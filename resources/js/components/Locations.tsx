import { useEffect, useState, type FormEvent } from 'react';
import { useTranslation } from 'react-i18next';
import {
    api,
    errorMessage,
    type Location,
    type Organization,
    type Page,
} from '../lib/api';
export function Locations({ organization }: { organization: Organization }) {
    const { t } = useTranslation();
    const [page, setPage] = useState(1);
    const [result, setResult] = useState<Page<Location> | null>(null);
    const [error, setError] = useState('');
    const [revision, setRevision] = useState(0);
    const [editing, setEditing] = useState<Location | 'new' | null>(null);
    const [busy, setBusy] = useState(false);
    const base = `/api/v1/organizations/${organization.id}/locations`;
    const manage = organization.permissions.includes('locations.manage');
    const view = organization.permissions.includes('locations.view');
    useEffect(() => {
        if (!view) return;
        const controller = new AbortController();
        api.get<Page<Location>>(`${base}?page=${page}`, controller.signal)
            .then((data) => {
                setResult(data);
                setError('');
            })
            .catch((err: unknown) => {
                if (!controller.signal.aborted) {
                    setResult(null);
                    setError(errorMessage(err));
                }
            });
        return () => controller.abort();
    }, [base, page, revision, view]);
    async function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        setBusy(true);
        setError('');
        const data = Object.fromEntries(new FormData(event.currentTarget));
        try {
            if (editing === 'new') await api.post(base, data);
            else if (editing) await api.patch(`${base}/${editing.id}`, data);
            setEditing(null);
            setRevision(revision + 1);
        } catch (err) {
            setError(errorMessage(err));
        } finally {
            setBusy(false);
        }
    }
    if (!view) return <p role="alert">{t('accessDenied')}</p>;
    const record = editing && editing !== 'new' ? editing : null;
    return (
        <section className="management-panel">
            <div className="section-heading">
                <h2>{t('locations')}</h2>
                {manage &&
                    organization.location_scope === 'all' &&
                    !editing && (
                        <button onClick={() => setEditing('new')}>
                            {t('addLocation')}
                        </button>
                    )}
            </div>
            {error && (
                <p role="alert" className="error">
                    {error}
                </p>
            )}
            {editing ? (
                <form
                    key={record?.id ?? 'new'}
                    className="management-form"
                    onSubmit={(event) => void submit(event)}
                >
                    <fieldset disabled={busy}>
                        <label>
                            {t('locationName')}
                            <input
                                name="name"
                                defaultValue={record?.name}
                                required
                                maxLength={160}
                            />
                        </label>
                        <label>
                            {t('address')}
                            <input
                                name="address"
                                defaultValue={record?.address ?? ''}
                                maxLength={300}
                            />
                        </label>
                        <label>
                            {t('city')}
                            <input
                                name="city"
                                defaultValue={record?.city ?? ''}
                                maxLength={100}
                            />
                        </label>
                        <label>
                            {t('countryCode')}
                            <input
                                name="country_code"
                                defaultValue={record?.country_code ?? ''}
                                pattern="[A-Z]{2}"
                                maxLength={2}
                            />
                        </label>
                        <label>
                            {t('status')}
                            <select
                                name="status"
                                defaultValue={record?.status ?? 'active'}
                            >
                                <option value="active">{t('active')}</option>
                                <option value="inactive">
                                    {t('inactive')}
                                </option>
                            </select>
                        </label>
                        <div className="form-actions">
                            <button className="primary" disabled={busy}>
                                {t(busy ? 'working' : 'save')}
                            </button>
                            <button
                                type="button"
                                onClick={() => setEditing(null)}
                            >
                                {t('cancel')}
                            </button>
                        </div>
                    </fieldset>
                </form>
            ) : !result && !error ? (
                <p role="status">{t('loading')}</p>
            ) : (
                result && (
                    <>
                        <div className="record-list">
                            {result.data.length === 0 && (
                                <p>{t('noLocations')}</p>
                            )}
                            {result.data.map((location) => (
                                <article
                                    key={location.id}
                                    className="record-row"
                                >
                                    <div>
                                        <h3>{location.name}</h3>
                                        <p>
                                            {[
                                                location.address,
                                                location.city,
                                                location.country_code,
                                            ]
                                                .filter(Boolean)
                                                .join(', ')}
                                        </p>
                                        <span className="status-badge">
                                            {t(location.status)}
                                        </span>
                                    </div>
                                    {manage && (
                                        <button
                                            onClick={() => setEditing(location)}
                                            aria-label={`${t('edit')} ${location.name}`}
                                        >
                                            {t('edit')}
                                        </button>
                                    )}
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
                )
            )}
        </section>
    );
}
