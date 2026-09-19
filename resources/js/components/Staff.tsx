import { useEffect, useState, type FormEvent } from 'react';
import { Link } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import {
    api,
    errorMessage,
    type Organization,
    type Page,
    type StaffMembership,
} from '../lib/api';
export function Staff({
    organization,
    onChanged,
}: {
    organization: Organization;
    onChanged: () => void;
}) {
    const { t } = useTranslation();
    const [result, setResult] = useState<Page<StaffMembership> | null>(null);
    const [page, setPage] = useState(1);
    const [error, setError] = useState('');
    const [revision, setRevision] = useState(0);
    const [editing, setEditing] = useState<StaffMembership | null>(null);
    const [busy, setBusy] = useState(false);
    const base = `/api/v1/organizations/${organization.id}/staff`;
    const view = organization.permissions.includes('staff.view');
    const manage = organization.permissions.includes('staff.manage');
    const managePermissions =
        organization.permissions.includes('permissions.manage');
    useEffect(() => {
        if (!view) return;
        const controller = new AbortController();
        api.get<Page<StaffMembership>>(
            `${base}?page=${page}`,
            controller.signal,
        )
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
    const trainerManagement =
        organization.permissions.includes('trainers.manage') &&
        organization.permissions.includes('trainers.view') &&
        organization.location_scope === 'all';
    async function enableTrainer(membership: StaffMembership) {
        setBusy(true);
        setError('');
        try {
            await api.post(
                `/api/v1/organizations/${organization.id}/trainers`,
                { membership_id: membership.id },
            );
            setRevision((value) => value + 1);
        } catch (err) {
            setError(errorMessage(err));
        } finally {
            setBusy(false);
        }
    }
    async function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        if (!editing) return;
        setBusy(true);
        setError('');
        const form = new FormData(event.currentTarget);
        const data: Record<string, unknown> = { status: form.get('status') };
        if (managePermissions) {
            const role = form.get('role');
            if (role !== editing.role) data.role = role;
            else if (editing.permissions)
                data.permissions = form.getAll('permissions');
        }
        try {
            await api.patch(`${base}/${editing.id}`, data);
            setEditing(null);
            setRevision(revision + 1);
            onChanged();
        } catch (err) {
            setError(errorMessage(err));
        } finally {
            setBusy(false);
        }
    }
    if (!view) return <p role="alert">{t('accessDenied')}</p>;
    return (
        <section className="management-panel">
            <h2>{t('staff')}</h2>
            <p className="muted">{t('staffExplanation')}</p>
            {error && (
                <p className="error" role="alert">
                    {error}
                </p>
            )}
            {editing ? (
                <form
                    className="management-form"
                    key={editing.id}
                    onSubmit={(event) => void submit(event)}
                >
                    <h3>{editing.user.name}</h3>
                    <fieldset disabled={busy}>
                        <label>
                            {t('status')}
                            <select name="status" defaultValue={editing.status}>
                                <option value="active">{t('active')}</option>
                                <option value="suspended">
                                    {t('suspended')}
                                </option>
                                <option value="revoked">{t('revoked')}</option>
                            </select>
                        </label>
                        {managePermissions && (
                            <>
                                <label>
                                    {t('role')}
                                    <select
                                        name="role"
                                        defaultValue={editing.role}
                                    >
                                        {[
                                            'owner',
                                            'administrator',
                                            'trainer',
                                            'staff',
                                        ].map((role) => (
                                            <option key={role} value={role}>
                                                {t(`role_${role}`)}
                                            </option>
                                        ))}
                                    </select>
                                </label>
                                <p className="muted">{t('roleBundleHint')}</p>
                                {editing.permissions ? (
                                    <fieldset className="permission-list">
                                        <legend>{t('permissions')}</legend>
                                        {organization.permissions.map(
                                            (permission) => (
                                                <label key={permission}>
                                                    <input
                                                        type="checkbox"
                                                        name="permissions"
                                                        value={permission}
                                                        defaultChecked={editing.permissions?.includes(
                                                            permission,
                                                        )}
                                                    />
                                                    {t(
                                                        `permission_${permission.replaceAll('.', '_')}`,
                                                    )}
                                                </label>
                                            ),
                                        )}
                                    </fieldset>
                                ) : (
                                    <p className="muted">
                                        {t('permissionsRestricted')}
                                    </p>
                                )}
                            </>
                        )}
                        <p className="muted">{t('revocationWarning')}</p>
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
                            {result.data.map((membership) => (
                                <article
                                    className="record-row"
                                    key={membership.id}
                                >
                                    <div>
                                        <h3>{membership.user.name}</h3>
                                        <p>
                                            {t(`role_${membership.role}`)} ·{' '}
                                            {t(membership.status)} ·{' '}
                                            {t(
                                                membership.location_scope ===
                                                    'all'
                                                    ? 'allLocations'
                                                    : 'selectedLocations',
                                            )}
                                        </p>
                                        {Object.hasOwn(
                                            membership,
                                            'trainer_profile',
                                        ) && (
                                            <p>
                                                {t('trainerStatus')}:{' '}
                                                {membership.trainer_profile
                                                    ? t(
                                                          `memberStatus_${membership.trainer_profile.status}`,
                                                      )
                                                    : t('trainerNotEnabled')}
                                            </p>
                                        )}
                                        {membership.permissions ? (
                                            <ul
                                                className="permission-summary"
                                                aria-label={t('permissions')}
                                            >
                                                {membership.permissions.map(
                                                    (permission) => (
                                                        <li key={permission}>
                                                            {t(
                                                                `permission_${permission.replaceAll('.', '_')}`,
                                                            )}
                                                        </li>
                                                    ),
                                                )}
                                            </ul>
                                        ) : (
                                            <p className="muted">
                                                {t('permissionsRestricted')}
                                            </p>
                                        )}
                                    </div>
                                    {membership.trainer_profile && (
                                        <Link
                                            to={`/trainers/${membership.trainer_profile.id}`}
                                        >
                                            {t('openTrainer')}
                                        </Link>
                                    )}
                                    {trainerManagement &&
                                        membership.trainer_profile === null &&
                                        membership.status === 'active' && (
                                            <button
                                                disabled={busy}
                                                onClick={() =>
                                                    void enableTrainer(
                                                        membership,
                                                    )
                                                }
                                                aria-label={`${t('enableTrainer')} ${membership.user.name}`}
                                            >
                                                {t('enableTrainer')}
                                            </button>
                                        )}
                                    {manage &&
                                        membership.status !== 'revoked' && (
                                            <button
                                                onClick={() =>
                                                    setEditing(membership)
                                                }
                                                aria-label={`${t('edit')} ${membership.user.name}`}
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
