import { useEffect, useState, type FormEvent } from 'react';
import { useTranslation } from 'react-i18next';
import {
    api,
    errorMessage,
    type Organization,
    type Page,
    type Location,
    type StudioMember,
} from '../lib/api';

type MemberResponse = {
    data: StudioMember;
    meta?: { duplicate_warning: boolean };
};
type Search = {
    page: number;
    search?: string;
    status?: string;
    digital_access_mode?: string;
};

function LocationChoice({
    organization,
    member,
}: {
    organization: Organization;
    member: StudioMember | null;
}) {
    const { t } = useTranslation();
    const [locations, setLocations] = useState<Location[]>([]);
    const [page, setPage] = useState(1);
    const [lastPage, setLastPage] = useState(1);
    const [error, setError] = useState('');
    const [loading, setLoading] = useState(true);
    useEffect(() => {
        const controller = new AbortController();
        api.get<Page<Location>>(
            `/api/v1/organizations/${organization.id}/locations?per_page=100&page=${page}`,
            controller.signal,
        )
            .then((result) => {
                setLocations((previous) =>
                    page === 1 ? result.data : [...previous, ...result.data],
                );
                setLastPage(result.meta.last_page);
                setLoading(false);
            })
            .catch((err: unknown) => {
                if (!controller.signal.aborted) {
                    setError(errorMessage(err));
                    setLoading(false);
                }
            });
        return () => controller.abort();
    }, [organization.id, page]);
    const preferred = member?.preferred_location;
    return (
        <div>
            <label>
                {t('memberHomeLocation')}
                <select
                    name="preferred_location_id"
                    defaultValue={preferred?.id ?? ''}
                >
                    <option value="">{t('noPreference')}</option>
                    {preferred &&
                        !locations.some(
                            (item) =>
                                item.id === preferred.id &&
                                item.status === 'active',
                        ) && (
                            <option value={preferred.id}>
                                {preferred.name}
                            </option>
                        )}
                    {locations
                        .filter((item) => item.status === 'active')
                        .map((item) => (
                            <option key={item.id} value={item.id}>
                                {item.name}
                            </option>
                        ))}
                </select>
            </label>
            {error && <p role="alert">{error}</p>}
            {loading && <p role="status">{t('loading')}</p>}
            {page < lastPage && (
                <button
                    type="button"
                    disabled={loading}
                    onClick={() => {
                        setLoading(true);
                        setPage(page + 1);
                    }}
                >
                    {t('loadMoreLocations')}
                </button>
            )}
        </div>
    );
}

function MemberForm({
    organization,
    member,
    onSaved,
    onCancel,
}: {
    organization: Organization;
    member: StudioMember | null;
    onSaved: (response: MemberResponse) => void;
    onCancel: () => void;
}) {
    const { t } = useTranslation();
    const [error, setError] = useState('');
    const [busy, setBusy] = useState(false);
    const notesEditable =
        organization.permissions.includes('members.notes.manage') &&
        (!member || Object.hasOwn(member, 'notes'));
    async function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        setBusy(true);
        setError('');
        const fields = new FormData(event.currentTarget);
        const data = Object.fromEntries(
            [...fields.entries()].map(([key, value]) => [
                key,
                value === '' ? null : value,
            ]),
        );
        const base = `/api/v1/organizations/${organization.id}/members`;
        try {
            onSaved(
                member
                    ? await api.patch<MemberResponse>(
                          `${base}/${member.id}`,
                          data,
                      )
                    : await api.post<MemberResponse>(base, data),
            );
        } catch (err) {
            setError(errorMessage(err));
        } finally {
            setBusy(false);
        }
    }
    return (
        <form
            className="management-form member-form"
            onSubmit={(event) => void submit(event)}
        >
            <h3>{t(member ? 'editClient' : 'addClient')}</h3>
            <fieldset disabled={busy}>
                <label>
                    {t('firstName')}
                    <input
                        name="first_name"
                        defaultValue={member?.first_name}
                        required
                        maxLength={100}
                        autoComplete="off"
                    />
                </label>
                <label>
                    {t('lastName')}
                    <input
                        name="last_name"
                        defaultValue={member?.last_name}
                        required
                        maxLength={100}
                        autoComplete="off"
                    />
                </label>
                <label>
                    {t('memberPhone')}
                    <input
                        name="phone"
                        type="tel"
                        defaultValue={member?.phone ?? ''}
                        maxLength={40}
                        aria-describedby="phone-hint"
                        autoComplete="off"
                    />
                </label>
                <p id="phone-hint" className="muted">
                    {t('phoneHint')}
                </p>
                <label>
                    {t('email')}
                    <input
                        name="email"
                        type="email"
                        defaultValue={member?.email ?? ''}
                        maxLength={254}
                        autoComplete="off"
                    />
                </label>
                <label>
                    {t('memberMode')}
                    <select
                        name="digital_access_mode"
                        defaultValue={member?.digital_access_mode ?? 'offline'}
                    >
                        {['offline', 'assisted', 'app'].map((mode) => (
                            <option key={mode} value={mode}>
                                {t(`mode_${mode}`)}
                            </option>
                        ))}
                    </select>
                </label>
                <p className="muted">{t('modeHint')}</p>
                <label>
                    {t('preferredLocale')}
                    <select
                        name="preferred_locale"
                        defaultValue={
                            member?.preferred_locale ?? organization.locale
                        }
                    >
                        <option value="mk">Македонски</option>
                        <option value="en">English</option>
                    </select>
                </label>
                {organization.permissions.includes('locations.view') && (
                    <LocationChoice
                        organization={organization}
                        member={member}
                    />
                )}
                {notesEditable && (
                    <label>
                        {t('memberNotes')}
                        <textarea
                            name="notes"
                            defaultValue={member?.notes ?? ''}
                            maxLength={2000}
                            rows={4}
                            aria-describedby="notes-hint"
                        />
                        <span id="notes-hint" className="muted">
                            {t('notesHint')}
                        </span>
                    </label>
                )}
                <div className="form-actions">
                    <button className="primary" disabled={busy}>
                        {t(busy ? 'working' : 'save')}
                    </button>
                    <button type="button" onClick={onCancel}>
                        {t('cancel')}
                    </button>
                </div>
            </fieldset>
            {error && (
                <p role="alert" className="error">
                    {error}
                </p>
            )}
        </form>
    );
}

export function Members({ organization }: { organization: Organization }) {
    const { t } = useTranslation();
    const [query, setQuery] = useState<Search>({ page: 1 });
    const [result, setResult] = useState<Page<StudioMember> | null>(null);
    const [profile, setProfile] = useState<StudioMember | null>(null);
    const [mode, setMode] = useState<'list' | 'profile' | 'new' | 'edit'>(
        'list',
    );
    const [error, setError] = useState('');
    const [notice, setNotice] = useState('');
    const [busy, setBusy] = useState(false);
    const [duplicate, setDuplicate] = useState(false);
    const [revision, setRevision] = useState(0);
    const base = `/api/v1/organizations/${organization.id}/members`;
    const allowed =
        organization.permissions.includes('members.view') &&
        organization.location_scope === 'all';
    useEffect(() => {
        if (!allowed || mode !== 'list') return;
        const controller = new AbortController();
        api.post<Page<StudioMember>>(`${base}/search`, query, controller.signal)
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
    }, [base, query, allowed, mode, revision]);
    function search(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        const data = new FormData(event.currentTarget);
        const next: Search = { page: 1 };
        for (const key of [
            'search',
            'status',
            'digital_access_mode',
        ] as const) {
            const value = String(data.get(key) ?? '').trim();
            if (value) next[key] = value;
        }
        setResult(null);
        setError('');
        setQuery(next);
    }
    async function open(id: string) {
        setBusy(true);
        setError('');
        setNotice('');
        setDuplicate(false);
        try {
            const response = await api.get<MemberResponse>(`${base}/${id}`);
            setProfile(response.data);
            setMode('profile');
        } catch (err) {
            setError(errorMessage(err));
        } finally {
            setBusy(false);
        }
    }
    function saved(response: MemberResponse) {
        setProfile(response.data);
        setDuplicate(response.meta?.duplicate_warning ?? false);
        setMode('profile');
        setNotice('saved');
        setRevision(revision + 1);
    }
    async function changeStatus(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        if (!profile) return;
        const status = new FormData(event.currentTarget).get('status');
        setBusy(true);
        setError('');
        setNotice('');
        try {
            const response = await api.patch<MemberResponse>(
                `${base}/${profile.id}/status`,
                { status },
            );
            setProfile(response.data);
            setNotice('saved');
            setRevision(revision + 1);
        } catch (err) {
            setError(errorMessage(err));
        } finally {
            setBusy(false);
        }
    }
    if (!allowed)
        return (
            <p role="alert">
                {t(
                    organization.location_scope === 'selected'
                        ? 'memberScopeDenied'
                        : 'accessDenied',
                )}
            </p>
        );
    return (
        <section className="management-panel member-directory">
            <div className="section-heading">
                <h2>
                    {t(
                        mode === 'list'
                            ? 'clientDirectory'
                            : mode === 'new'
                              ? 'addClient'
                              : 'clientProfile',
                    )}
                </h2>
                {mode === 'list' &&
                    organization.permissions.includes('members.create') && (
                        <button
                            onClick={() => {
                                setMode('new');
                                setError('');
                                setNotice('');
                            }}
                        >
                            {t('addClient')}
                        </button>
                    )}
                {mode !== 'list' && (
                    <button
                        onClick={() => {
                            setMode('list');
                            setProfile(null);
                            setResult(null);
                            setError('');
                            setNotice('');
                            setDuplicate(false);
                        }}
                    >
                        {t('backToClients')}
                    </button>
                )}
            </div>
            {error && (
                <p role="alert" className="error">
                    {error}
                </p>
            )}
            {notice && <p role="status">{t(notice)}</p>}
            {duplicate && (
                <p className="notice" role="status">
                    {t('duplicateClientWarning')}
                </p>
            )}
            {(mode === 'new' || mode === 'edit') && (
                <MemberForm
                    key={profile?.id ?? 'new'}
                    organization={organization}
                    member={mode === 'edit' ? profile : null}
                    onSaved={saved}
                    onCancel={() =>
                        setMode(mode === 'edit' ? 'profile' : 'list')
                    }
                />
            )}
            {mode === 'profile' && profile && (
                <>
                    <div className="section-heading">
                        <h3>
                            {profile.first_name} {profile.last_name}
                        </h3>
                        {organization.permissions.includes('members.update') &&
                            profile.status !== 'archived' && (
                                <button
                                    onClick={() => {
                                        setMode('edit');
                                        setNotice('');
                                    }}
                                >
                                    {t('editClient')}
                                </button>
                            )}
                    </div>
                    <dl className="member-details">
                        <div>
                            <dt>{t('memberPhone')}</dt>
                            <dd>{profile.phone ?? t('notProvided')}</dd>
                        </div>
                        <div>
                            <dt>{t('email')}</dt>
                            <dd>{profile.email ?? t('notProvided')}</dd>
                        </div>
                        <div>
                            <dt>{t('memberMode')}</dt>
                            <dd>{t(`mode_${profile.digital_access_mode}`)}</dd>
                        </div>
                        <div>
                            <dt>{t('preferredLocale')}</dt>
                            <dd>
                                {profile.preferred_locale === 'mk'
                                    ? 'Македонски'
                                    : 'English'}
                            </dd>
                        </div>
                        {Object.hasOwn(profile, 'preferred_location') && (
                            <div>
                                <dt>{t('memberHomeLocation')}</dt>
                                <dd>
                                    {profile.preferred_location?.name ??
                                        t('noPreference')}
                                </dd>
                            </div>
                        )}
                        <div>
                            <dt>{t('status')}</dt>
                            <dd>{t(`memberStatus_${profile.status}`)}</dd>
                        </div>
                    </dl>
                    {Object.hasOwn(profile, 'notes') && (
                        <section className="member-notes">
                            <h4>{t('memberNotes')}</h4>
                            <p>{profile.notes ?? t('notProvided')}</p>
                        </section>
                    )}
                    {profile.status === 'archived' && (
                        <p className="notice">{t('archivedClientHint')}</p>
                    )}
                    {organization.permissions.includes(
                        'members.status.manage',
                    ) && (
                        <form
                            className="member-status-form"
                            key={profile.status}
                            onSubmit={(event) => void changeStatus(event)}
                        >
                            <label>
                                {t('changeClientStatus')}
                                <select
                                    name="status"
                                    defaultValue={profile.status}
                                >
                                    {(profile.status === 'archived'
                                        ? ['archived', 'inactive']
                                        : ['active', 'inactive', 'archived']
                                    ).map((status) => (
                                        <option key={status} value={status}>
                                            {t(`memberStatus_${status}`)}
                                        </option>
                                    ))}
                                </select>
                            </label>
                            <button disabled={busy}>{t('applyStatus')}</button>
                            <p className="muted">{t('archiveHint')}</p>
                        </form>
                    )}
                </>
            )}
            {mode === 'list' && (
                <>
                    <form className="member-filters" onSubmit={search}>
                        <label>
                            {t('searchClients')}
                            <input
                                name="search"
                                type="search"
                                minLength={2}
                                maxLength={100}
                                defaultValue={query.search}
                                aria-describedby="search-hint"
                                autoComplete="off"
                            />
                        </label>
                        <label>
                            {t('status')}
                            <select
                                name="status"
                                defaultValue={query.status ?? ''}
                            >
                                <option value="">{t('currentClients')}</option>
                                {['active', 'inactive', 'archived', 'all'].map(
                                    (status) => (
                                        <option key={status} value={status}>
                                            {t(
                                                status === 'all'
                                                    ? 'allStatuses'
                                                    : `memberStatus_${status}`,
                                            )}
                                        </option>
                                    ),
                                )}
                            </select>
                        </label>
                        <label>
                            {t('memberMode')}
                            <select
                                name="digital_access_mode"
                                defaultValue={query.digital_access_mode ?? ''}
                            >
                                <option value="">{t('allModes')}</option>
                                {['offline', 'assisted', 'app'].map(
                                    (access) => (
                                        <option key={access} value={access}>
                                            {t(`mode_${access}`)}
                                        </option>
                                    ),
                                )}
                            </select>
                        </label>
                        <button className="primary">{t('search')}</button>
                    </form>
                    <p id="search-hint" className="muted">
                        {t('memberSearchHint')}
                    </p>
                    {!result && !error && <p role="status">{t('loading')}</p>}
                    {result && (
                        <>
                            <div className="record-list">
                                {result.data.length === 0 && (
                                    <p>{t('noClients')}</p>
                                )}
                                {result.data.map((member) => (
                                    <article
                                        className="record-row"
                                        key={member.id}
                                    >
                                        <div>
                                            <h3>
                                                {member.first_name}{' '}
                                                {member.last_name}
                                            </h3>
                                            <p>
                                                {member.phone ?? t('noPhone')}
                                                {member.preferred_location && (
                                                    <>
                                                        {' '}
                                                        ·{' '}
                                                        {
                                                            member
                                                                .preferred_location
                                                                .name
                                                        }
                                                    </>
                                                )}
                                            </p>
                                            <p>
                                                {t(
                                                    `mode_${member.digital_access_mode}`,
                                                )}{' '}
                                                ·{' '}
                                                {t(
                                                    `memberStatus_${member.status}`,
                                                )}
                                            </p>
                                        </div>
                                        <button
                                            disabled={busy}
                                            onClick={() => void open(member.id)}
                                            aria-label={`${t('openClient')} ${member.first_name} ${member.last_name}`}
                                        >
                                            {t('openClient')}
                                        </button>
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
                                            page: query.page - 1,
                                        });
                                    }}
                                >
                                    {t('previous')}
                                </button>
                                <span>
                                    {result.meta.current_page} /{' '}
                                    {result.meta.last_page} ·{' '}
                                    {t('clientTotal', {
                                        count: result.meta.total,
                                    })}
                                </span>
                                <button
                                    disabled={
                                        query.page >= result.meta.last_page
                                    }
                                    onClick={() => {
                                        setResult(null);
                                        setQuery({
                                            ...query,
                                            page: query.page + 1,
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
