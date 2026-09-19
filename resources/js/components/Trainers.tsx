import { useEffect, useState, type FormEvent } from 'react';
import { Link } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import {
    api,
    errorMessage,
    type Organization,
    type Page,
    type Location,
    type TrainerProfile,
} from '../lib/api';

function TrainerLocationFilter({
    organization,
}: {
    organization: Organization;
}) {
    const { t } = useTranslation();
    const [locations, setLocations] = useState<Location[]>([]);
    const [page, setPage] = useState(1);
    const [last, setLast] = useState(1);
    const [error, setError] = useState('');
    useEffect(() => {
        const controller = new AbortController();
        api.get<Page<Location>>(
            `/api/v1/organizations/${organization.id}/locations?per_page=100&page=${page}`,
            controller.signal,
        )
            .then((result) => {
                setLocations((current) =>
                    page === 1 ? result.data : [...current, ...result.data],
                );
                setLast(result.meta.last_page);
            })
            .catch((err: unknown) => {
                if (!controller.signal.aborted) setError(errorMessage(err));
            });
        return () => controller.abort();
    }, [organization.id, page]);
    return (
        <div>
            <label>
                {t('trainerLocationFilter')}
                <select name="location_id">
                    <option value="">{t('allLocations')}</option>
                    {locations
                        .filter((item) => item.status === 'active')
                        .map((item) => (
                            <option key={item.id} value={item.id}>
                                {item.name}
                            </option>
                        ))}
                </select>
            </label>
            {page < last && (
                <button type="button" onClick={() => setPage(page + 1)}>
                    {t('loadMoreLocations')}
                </button>
            )}
            {error && <p role="alert">{error}</p>}
        </div>
    );
}
function TrainerLocations({ base }: { base: string }) {
    const { t } = useTranslation();
    const [result, setResult] = useState<Page<{
        id: string;
        name: string;
    }> | null>(null);
    const [page, setPage] = useState(1);
    const [error, setError] = useState('');
    useEffect(() => {
        const controller = new AbortController();
        api.get<Page<{ id: string; name: string }>>(
            `${base}/locations?page=${page}`,
            controller.signal,
        )
            .then(setResult)
            .catch((err: unknown) => {
                if (!controller.signal.aborted) setError(errorMessage(err));
            });
        return () => controller.abort();
    }, [base, page]);
    return (
        <section>
            <h3>{t('trainerEligibleLocations')}</h3>
            <p className="muted">{t('trainerLocationsHint')}</p>
            {error && <p role="alert">{error}</p>}
            {!result && !error ? (
                <p role="status">{t('loading')}</p>
            ) : (
                result && (
                    <>
                        {result.data.length ? (
                            <ul>
                                {result.data.map((location) => (
                                    <li key={location.id}>{location.name}</li>
                                ))}
                            </ul>
                        ) : (
                            <p>{t('trainerNoLocations')}</p>
                        )}
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
export function Trainers({
    organization,
    trainerId,
}: {
    organization: Organization;
    trainerId?: string;
}) {
    const { t } = useTranslation();
    const [result, setResult] = useState<Page<TrainerProfile> | null>(null);
    const [profile, setProfile] = useState<TrainerProfile | null>(null);
    const [query, setQuery] = useState<Record<string, string | number>>({
        page: 1,
    });
    const [error, setError] = useState('');
    const [saved, setSaved] = useState(false);
    const [busy, setBusy] = useState(false);
    const [revision, setRevision] = useState(0);
    const base = `/api/v1/organizations/${organization.id}/trainers`;
    const view = organization.permissions.includes('trainers.view');
    const manage =
        organization.permissions.includes('trainers.manage') &&
        organization.location_scope === 'all';
    useEffect(() => {
        if (!view) return;
        const controller = new AbortController();
        const request = trainerId
            ? api
                  .get<{ data: TrainerProfile }>(
                      `${base}/${trainerId}`,
                      controller.signal,
                  )
                  .then((response) => setProfile(response.data))
            : api
                  .post<Page<TrainerProfile>>(
                      `${base}/search`,
                      query,
                      controller.signal,
                  )
                  .then(setResult);
        request
            .then(() => setError(''))
            .catch((err: unknown) => {
                if (!controller.signal.aborted) {
                    setResult(null);
                    setProfile(null);
                    setError(errorMessage(err));
                }
            });
        return () => controller.abort();
    }, [base, trainerId, query, view]);
    function search(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        const fields = new FormData(event.currentTarget);
        const next: Record<string, string | number> = { page: 1 };
        for (const [key, value] of fields.entries())
            if (String(value).trim()) next[key] = String(value).trim();
        setResult(null);
        setError('');
        setQuery(next);
    }
    async function update(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        if (!profile) return;
        const data = Object.fromEntries(
            [...new FormData(event.currentTarget)].map(([key, value]) => [
                key,
                value === '' ? null : value,
            ]),
        );
        setBusy(true);
        setError('');
        setSaved(false);
        try {
            const response = await api.patch<{ data: TrainerProfile }>(
                `${base}/${profile.id}`,
                data,
            );
            setProfile(response.data);
            setSaved(true);
        } catch (err) {
            setError(errorMessage(err));
        } finally {
            setBusy(false);
        }
    }
    async function status() {
        if (!profile) return;
        setBusy(true);
        setError('');
        setSaved(false);
        try {
            const response = await api.patch<{ data: TrainerProfile }>(
                `${base}/${profile.id}/status`,
                { status: profile.status === 'active' ? 'inactive' : 'active' },
            );
            setProfile(response.data);
            setRevision(revision + 1);
            setSaved(true);
        } catch (err) {
            setError(errorMessage(err));
        } finally {
            setBusy(false);
        }
    }
    if (!view) return <p role="alert">{t('accessDenied')}</p>;
    return (
        <section className="management-panel trainer-directory">
            <div className="section-heading">
                <h2>{t(trainerId ? 'trainerProfile' : 'trainerDirectory')}</h2>
                {trainerId ? (
                    <Link
                        to="/trainers"
                        onClick={() => {
                            setError('');
                            setSaved(false);
                            setResult(null);
                        }}
                    >
                        {t('backToTrainers')}
                    </Link>
                ) : (
                    organization.permissions.includes('staff.view') && (
                        <Link to="/staff">{t('enableFromStaff')}</Link>
                    )
                )}
            </div>
            {error && (
                <p role="alert" className="error">
                    {error}
                </p>
            )}
            {saved && trainerId && <p role="status">{t('saved')}</p>}
            {trainerId ? (
                profile?.id === trainerId ? (
                    <>
                        <h3>{profile.display_name}</h3>
                        <p className="muted">
                            {t('trainerProfileExplanation')}
                        </p>
                        <dl className="member-details">
                            <div>
                                <dt>{t('trainerStatus')}</dt>
                                <dd>{t(`memberStatus_${profile.status}`)}</dd>
                            </div>
                            <div>
                                <dt>{t('trainerStaffStatus')}</dt>
                                <dd>{t(profile.staff_status)}</dd>
                            </div>
                            <div>
                                <dt>{t('role')}</dt>
                                <dd>{t(`role_${profile.staff_role}`)}</dd>
                            </div>
                            <div>
                                <dt>{t('trainerAssignment')}</dt>
                                <dd>
                                    {t(
                                        profile.assignable
                                            ? 'trainerAssignable'
                                            : 'trainerNotAssignable',
                                    )}
                                </dd>
                            </div>
                        </dl>
                        {manage ? (
                            <form
                                className="management-form"
                                onSubmit={(event) => void update(event)}
                                key={profile.id}
                            >
                                <fieldset disabled={busy}>
                                    <label>
                                        {t('trainerDisplayName')}
                                        <input
                                            name="display_name"
                                            defaultValue={
                                                profile.display_name_override ??
                                                ''
                                            }
                                            maxLength={160}
                                        />
                                        <span className="muted">
                                            {t('trainerDisplayHint')}
                                        </span>
                                    </label>
                                    <label>
                                        {t('trainerBio')}
                                        <textarea
                                            name="bio"
                                            defaultValue={profile.bio ?? ''}
                                            maxLength={1000}
                                            rows={4}
                                        />
                                        <span className="muted">
                                            {t('trainerBioHint')}
                                        </span>
                                    </label>
                                    <button className="primary" disabled={busy}>
                                        {t(busy ? 'working' : 'save')}
                                    </button>
                                </fieldset>
                            </form>
                        ) : (
                            <section>
                                <h3>{t('trainerBio')}</h3>
                                <p className="trainer-bio">
                                    {profile.bio ?? t('notProvided')}
                                </p>
                            </section>
                        )}
                        {manage && (
                            <div className="trainer-lifecycle">
                                <button
                                    disabled={
                                        busy ||
                                        (profile.status === 'inactive' &&
                                            profile.staff_status !== 'active')
                                    }
                                    onClick={() => void status()}
                                >
                                    {t(
                                        profile.status === 'active'
                                            ? 'deactivateTrainer'
                                            : 'activateTrainer',
                                    )}
                                </button>
                                <p className="muted">
                                    {t('trainerStatusHint')}
                                </p>
                            </div>
                        )}
                        <TrainerLocations
                            key={`${profile.id}:${profile.status}:${revision}`}
                            base={`${base}/${profile.id}`}
                        />
                        {organization.permissions.includes('staff.view') && (
                            <p>
                                <Link to="/staff">
                                    {t('manageStaffAccess')}
                                </Link>
                            </p>
                        )}
                    </>
                ) : (
                    !error && <p role="status">{t('loading')}</p>
                )
            ) : (
                <>
                    <form
                        className="member-filters trainer-filters"
                        onSubmit={search}
                    >
                        <label>
                            {t('trainerSearch')}
                            <input
                                name="search"
                                type="search"
                                minLength={2}
                                maxLength={100}
                                autoComplete="off"
                                defaultValue={query.search}
                            />
                        </label>
                        <label>
                            {t('trainerStatus')}
                            <select
                                name="status"
                                defaultValue={query.status ?? 'all'}
                            >
                                {['all', 'active', 'inactive'].map((status) => (
                                    <option key={status} value={status}>
                                        {t(
                                            status === 'all'
                                                ? 'allStatuses'
                                                : `memberStatus_${status}`,
                                        )}
                                    </option>
                                ))}
                            </select>
                        </label>
                        {organization.permissions.includes(
                            'locations.view',
                        ) && (
                            <TrainerLocationFilter
                                organization={organization}
                            />
                        )}
                        <button className="primary">{t('search')}</button>
                    </form>
                    {!result && !error && <p role="status">{t('loading')}</p>}
                    {result && (
                        <>
                            <div className="record-list">
                                {result.data.length === 0 && (
                                    <p>{t('noTrainers')}</p>
                                )}
                                {result.data.map((trainer) => (
                                    <article
                                        className="record-row"
                                        key={trainer.id}
                                    >
                                        <div>
                                            <h3>{trainer.display_name}</h3>
                                            <p>
                                                {t(
                                                    `role_${trainer.staff_role}`,
                                                )}{' '}
                                                ·{' '}
                                                {t(
                                                    `memberStatus_${trainer.status}`,
                                                )}
                                            </p>
                                            <p>
                                                {trainer.eligible_locations
                                                    .map(
                                                        (location) =>
                                                            location.name,
                                                    )
                                                    .join(', ') ||
                                                    t('trainerNoLocations')}
                                                {trainer.eligible_location_count >
                                                    trainer.eligible_locations
                                                        .length &&
                                                    ` · +${trainer.eligible_location_count - trainer.eligible_locations.length}`}
                                            </p>
                                            <p className="muted">
                                                {t(
                                                    trainer.assignable
                                                        ? 'trainerAssignable'
                                                        : 'trainerNotAssignable',
                                                )}
                                            </p>
                                        </div>
                                        <Link
                                            to={`/trainers/${trainer.id}`}
                                            onClick={() => {
                                                setError('');
                                                setSaved(false);
                                            }}
                                            aria-label={`${t('openTrainer')} ${trainer.display_name}`}
                                        >
                                            {t('openTrainer')}
                                        </Link>
                                    </article>
                                ))}
                            </div>
                            <div className="pagination">
                                <button
                                    disabled={query.page === 1}
                                    onClick={() => {
                                        setResult(null);
                                        setQuery({
                                            ...query,
                                            page: Number(query.page) - 1,
                                        });
                                    }}
                                >
                                    {t('previous')}
                                </button>
                                <span>
                                    {result.meta.current_page} /{' '}
                                    {result.meta.last_page}
                                </span>
                                <button
                                    disabled={
                                        Number(query.page) >=
                                        result.meta.last_page
                                    }
                                    onClick={() => {
                                        setResult(null);
                                        setQuery({
                                            ...query,
                                            page: Number(query.page) + 1,
                                        });
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
