import { useEffect, useState, type FormEvent } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import {
    api,
    errorMessage,
    type Organization,
    type Page,
    type TrainingGroup,
} from '../lib/api';
import { GroupSelect } from './GroupSelect';
import { GroupSchedule } from './GroupSchedule';

function GroupForm({
    organization,
    group,
    onSaved,
    onCancel,
}: {
    organization: Organization;
    group?: TrainingGroup;
    onSaved: (group: TrainingGroup) => void;
    onCancel: () => void;
}) {
    const { t } = useTranslation();
    const [location, setLocation] = useState(group?.location.id ?? '');
    const [trainer, setTrainer] = useState(group?.primary_trainer?.id ?? '');
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const selectLocations = organization.permissions.includes('locations.view');
    const selectTrainers = organization.permissions.includes('trainers.view');
    const reassign =
        !group?.schedule_rule_count ||
        organization.permissions.includes('schedule.manage');
    async function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        const fields = Object.fromEntries(new FormData(event.currentTarget));
        const data: Record<string, unknown> = {
            name: fields.name,
            description: fields.description || null,
            capacity: Number(fields.capacity),
        };
        if (!group || location !== group.location.id)
            data.location_id = location;
        if (!group || trainer !== (group.primary_trainer?.id ?? ''))
            data.primary_trainer_id = trainer || null;
        setBusy(true);
        setError('');
        const base = `/api/v1/organizations/${organization.id}/groups`;
        try {
            const result = group
                ? await api.patch<{ data: TrainingGroup }>(
                      `${base}/${group.id}`,
                      data,
                  )
                : await api.post<{ data: TrainingGroup }>(base, data);
            onSaved(result.data);
        } catch (err) {
            setError(errorMessage(err));
        } finally {
            setBusy(false);
        }
    }
    return (
        <form
            className="management-form"
            onSubmit={(event) => void submit(event)}
        >
            <h3>{t(group ? 'editGroup' : 'addGroup')}</h3>
            {error && (
                <p role="alert" className="error">
                    {error}
                </p>
            )}
            <fieldset disabled={busy}>
                <label>
                    {t('groupName')}
                    <input
                        name="name"
                        required
                        maxLength={160}
                        defaultValue={group?.name ?? ''}
                    />
                </label>
                <label>
                    {t('groupDescription')}
                    <textarea
                        name="description"
                        maxLength={2000}
                        rows={3}
                        defaultValue={group?.description ?? ''}
                    />
                </label>
                <label>
                    {t('groupCapacity')}
                    <input
                        name="capacity"
                        type="number"
                        min={1}
                        max={100}
                        step={1}
                        required
                        defaultValue={group?.capacity ?? 12}
                    />
                </label>
                {selectLocations && reassign ? (
                    <GroupSelect
                        organizationId={organization.id}
                        kind="locations"
                        name="location_id"
                        label={t('location')}
                        value={location}
                        onChange={(value) => {
                            setLocation(value);
                            setTrainer('');
                        }}
                        required
                        current={group?.location}
                    />
                ) : (
                    <p>
                        {group?.location.name ?? t('groupSelectionRestricted')}
                    </p>
                )}
                {selectTrainers && location && reassign ? (
                    <GroupSelect
                        key={location}
                        organizationId={organization.id}
                        kind="trainers"
                        name="primary_trainer_id"
                        label={t('primaryTrainer')}
                        value={trainer}
                        onChange={setTrainer}
                        locationId={location}
                        current={
                            group?.location.id === location &&
                            group.primary_trainer
                                ? {
                                      id: group.primary_trainer.id,
                                      name: group.primary_trainer.display_name,
                                  }
                                : undefined
                        }
                    />
                ) : (
                    <p>
                        {group?.primary_trainer?.display_name ??
                            t('trainerUnassigned')}
                    </p>
                )}
                <p className="muted">{t('groupSetupHint')}</p>
                <div className="form-actions">
                    <button
                        className="primary"
                        disabled={busy || (!group && !location)}
                    >
                        {t(busy ? 'working' : 'save')}
                    </button>
                    <button type="button" onClick={onCancel}>
                        {t('cancel')}
                    </button>
                </div>
            </fieldset>
        </form>
    );
}
export function Groups({
    organization,
    groupId,
    scheduleOnly = false,
}: {
    organization: Organization;
    groupId?: string;
    scheduleOnly?: boolean;
}) {
    const { t } = useTranslation();
    const navigate = useNavigate();
    const [result, setResult] = useState<Page<TrainingGroup> | null>(null);
    const [group, setGroup] = useState<TrainingGroup | null>(null);
    const [query, setQuery] = useState<Record<string, string | number>>({
        page: 1,
        status: scheduleOnly ? 'active' : 'all',
    });
    const [locationFilter, setLocationFilter] = useState('');
    const [trainerFilter, setTrainerFilter] = useState('');
    const [editing, setEditing] = useState(false);
    const [error, setError] = useState('');
    const [busy, setBusy] = useState(false);
    const [revision, setRevision] = useState(0);
    const base = `/api/v1/organizations/${organization.id}/groups`;
    const view =
        organization.permissions.includes('groups.view') &&
        (!scheduleOnly || organization.permissions.includes('schedule.view'));
    const manage = organization.permissions.includes('groups.manage');
    useEffect(() => {
        if (!view) return;
        const controller = new AbortController();
        const request = groupId
            ? api
                  .get<{ data: TrainingGroup }>(
                      `${base}/${groupId}`,
                      controller.signal,
                  )
                  .then((response) => setGroup(response.data))
            : api
                  .post<Page<TrainingGroup>>(
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
                    setGroup(null);
                    setError(errorMessage(err));
                }
            });
        return () => controller.abort();
    }, [base, groupId, query, view, revision]);
    function search(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        const data: Record<string, string | number> = { page: 1 };
        for (const [key, value] of new FormData(event.currentTarget))
            if (String(value).trim()) data[key] = String(value).trim();
        setResult(null);
        setQuery(data);
        setError('');
    }
    async function status(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        if (!group) return;
        const status = new FormData(event.currentTarget).get('status');
        setBusy(true);
        setError('');
        try {
            const response = await api.patch<{ data: TrainingGroup }>(
                `${base}/${group.id}/status`,
                { status },
            );
            setGroup(response.data);
        } catch (err) {
            setError(errorMessage(err));
        } finally {
            setBusy(false);
        }
    }
    function summary(item: TrainingGroup) {
        return (
            <>
                <p>
                    {item.location.name} ·{' '}
                    {item.primary_trainer?.display_name ??
                        t('trainerUnassigned')}
                </p>
                <p>
                    {t('capacityValue', { count: item.capacity })} ·{' '}
                    {t(`groupStatus_${item.status}`)}
                </p>
                {item.operational_issues.map((issue) => (
                    <p className="operational-issue" key={issue}>
                        {t(`groupIssue_${issue}`)}
                    </p>
                ))}
                {item.schedule_summary && (
                    <p>
                        {item.schedule_summary
                            .map(
                                (rule) =>
                                    `${t(`weekdayShort_${rule.weekday}`)} ${rule.start_time}–${rule.end_time} (${rule.effective_from} → ${rule.effective_until ?? t('openEnded')})`,
                            )
                            .join(' · ') || t('noScheduleRules')}
                        {(item.schedule_rule_count ?? 0) >
                            item.schedule_summary.length &&
                            ` · +${(item.schedule_rule_count ?? 0) - item.schedule_summary.length}`}
                    </p>
                )}
            </>
        );
    }
    if (!view) return <p role="alert">{t('accessDenied')}</p>;
    return (
        <section className="management-panel group-directory">
            <div className="section-heading">
                <h2>
                    {t(
                        groupId
                            ? 'groupProfile'
                            : scheduleOnly
                              ? 'weeklySchedule'
                              : 'groupDirectory',
                    )}
                </h2>
                {groupId ? (
                    <Link to="/groups">{t('backToGroups')}</Link>
                ) : (
                    manage &&
                    !editing && (
                        <button onClick={() => setEditing(true)}>
                            {t('addGroup')}
                        </button>
                    )
                )}
            </div>
            {error && (
                <p role="alert" className="error">
                    {error}
                </p>
            )}
            {editing && manage && (
                <GroupForm
                    organization={organization}
                    group={group ?? undefined}
                    onCancel={() => setEditing(false)}
                    onSaved={(saved) => {
                        setEditing(false);
                        setGroup(saved);
                        if (!groupId) void navigate(`/groups/${saved.id}`);
                    }}
                />
            )}
            {groupId ? (
                group && group.id === groupId ? (
                    <>
                        <h3>{group.name}</h3>
                        {summary(group)}
                        <p className="trainer-bio">{group.description}</p>
                        {manage && group.status !== 'archived' && !editing && (
                            <button onClick={() => setEditing(true)}>
                                {t('editGroup')}
                            </button>
                        )}
                        {manage && (
                            <form
                                className="member-status-form"
                                onSubmit={(event) => void status(event)}
                                key={`${group.id}:${group.status}`}
                            >
                                <label>
                                    {t('groupStatusChange')}
                                    <select
                                        name="status"
                                        defaultValue={group.status}
                                        disabled={busy}
                                    >
                                        {(group.status === 'archived'
                                            ? ['archived', 'inactive']
                                            : ['active', 'inactive', 'archived']
                                        ).map((value) => (
                                            <option key={value} value={value}>
                                                {t(`groupStatus_${value}`)}
                                            </option>
                                        ))}
                                    </select>
                                </label>
                                <button disabled={busy}>
                                    {t('applyStatus')}
                                </button>
                            </form>
                        )}
                        <GroupSchedule
                            organization={organization}
                            groupId={group.id}
                            archived={group.status === 'archived'}
                            onChanged={() => setRevision((value) => value + 1)}
                        />
                    </>
                ) : (
                    !error && <p role="status">{t('loading')}</p>
                )
            ) : (
                <>
                    <form
                        className="member-filters group-filters"
                        onSubmit={search}
                    >
                        <label>
                            {t('groupSearch')}
                            <input
                                type="search"
                                name="search"
                                minLength={2}
                                maxLength={100}
                                autoComplete="off"
                            />
                        </label>
                        <label>
                            {t('status')}
                            <select
                                name="status"
                                defaultValue={scheduleOnly ? 'active' : 'all'}
                            >
                                {['all', 'active', 'inactive', 'archived'].map(
                                    (value) => (
                                        <option key={value} value={value}>
                                            {t(
                                                value === 'all'
                                                    ? 'allStatuses'
                                                    : `groupStatus_${value}`,
                                            )}
                                        </option>
                                    ),
                                )}
                            </select>
                        </label>
                        {organization.permissions.includes(
                            'locations.view',
                        ) && (
                            <GroupSelect
                                organizationId={organization.id}
                                kind="locations"
                                name="location_id"
                                label={t('location')}
                                value={locationFilter}
                                onChange={setLocationFilter}
                            />
                        )}
                        {organization.permissions.includes('trainers.view') && (
                            <GroupSelect
                                organizationId={organization.id}
                                kind="trainers"
                                name="trainer_id"
                                label={t('primaryTrainer')}
                                value={trainerFilter}
                                onChange={setTrainerFilter}
                            />
                        )}
                        <button className="primary">{t('search')}</button>
                    </form>
                    {!result && !error && <p role="status">{t('loading')}</p>}
                    {result && (
                        <>
                            <div className="record-list">
                                {!result.data.length && <p>{t('noGroups')}</p>}
                                {result.data.map((item) => (
                                    <article
                                        className="record-row"
                                        key={item.id}
                                    >
                                        <div>
                                            <h3>{item.name}</h3>
                                            {summary(item)}
                                        </div>
                                        <Link
                                            to={`/groups/${item.id}`}
                                            aria-label={`${t('openGroup')} ${item.name}`}
                                        >
                                            {t('openGroup')}
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
