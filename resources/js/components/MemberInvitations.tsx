import { useEffect, useState, type FormEvent } from 'react';
import { useTranslation } from 'react-i18next';
import { api, errorMessage, type Organization } from '../lib/api';
type Invites = {
    linked: boolean;
    data: { id: string; status: string; expires_at: string }[];
    meta: { last_page: number };
};
export function MemberInvitations({
    organization,
    memberId,
}: {
    organization: Organization;
    memberId: string;
}) {
    const { t } = useTranslation();
    const [data, setData] = useState<Invites | null>(null);
    const [page, setPage] = useState(1);
    const [revision, setRevision] = useState(0);
    const [error, setError] = useState('');
    const [notice, setNotice] = useState('');
    const [busy, setBusy] = useState(false);
    const base = `/api/v1/organizations/${organization.id}`;
    const allowed =
        organization.location_scope === 'all' &&
        ['members.view', 'members.update', 'members.status.manage'].every((p) =>
            organization.permissions.includes(p),
        );
    useEffect(() => {
        if (!allowed) return;
        const c = new AbortController();
        api.get<Invites>(
            `${base}/members/${memberId}/invitations?page=${page}`,
            c.signal,
        )
            .then(setData)
            .catch((e: unknown) => {
                if (!c.signal.aborted) setError(errorMessage(e));
            });
        return () => c.abort();
    }, [allowed, base, memberId, page, revision]);
    async function invite(e: FormEvent<HTMLFormElement>) {
        e.preventDefault();
        setBusy(true);
        setError('');
        setNotice('');
        try {
            const p = await api.post<{ data: { delivery: string } }>(
                `${base}/members/${memberId}/invitations`,
                Object.fromEntries(new FormData(e.currentTarget)),
            );
            setNotice(
                t(
                    p.data.delivery === 'failed'
                        ? 'self.deliveryFailed'
                        : 'self.invited',
                ),
            );
            setRevision(revision + 1);
        } catch (err) {
            setError(errorMessage(err));
        } finally {
            setBusy(false);
        }
    }
    async function revoke(id: string) {
        setBusy(true);
        setError('');
        try {
            await api.post(`${base}/member-invitations/${id}/revoke`);
            setRevision(revision + 1);
        } catch (err) {
            setError(errorMessage(err));
        } finally {
            setBusy(false);
        }
    }
    if (!allowed) return null;
    return (
        <section className="member-card">
            <h3>{t('self.accountAccess')}</h3>
            {error && <p role="alert">{error}</p>}
            {notice && <p role="status">{notice}</p>}
            {data?.linked ? (
                <p>{t('self.linked')}</p>
            ) : (
                data && (
                    <form onSubmit={(e) => void invite(e)}>
                        <p>{t('self.inviteHelp')}</p>
                        <label>
                            {t('email')}
                            <input
                                type="email"
                                name="email"
                                required
                                maxLength={254}
                            />
                        </label>
                        <button disabled={busy}>{t('self.invite')}</button>
                    </form>
                )
            )}
            {data?.data.map((i) => (
                <p key={i.id}>
                    {t(`self.states.${i.status}`)} ·{' '}
                    {new Date(i.expires_at).toLocaleString()}{' '}
                    {i.status === 'pending' && (
                        <button
                            disabled={busy}
                            onClick={() => void revoke(i.id)}
                        >
                            {t('self.revoke')}
                        </button>
                    )}
                </p>
            ))}
            {data && data.meta.last_page > 1 && (
                <nav>
                    <button
                        disabled={page === 1}
                        onClick={() => setPage(page - 1)}
                    >
                        {t('previous')}
                    </button>
                    <button
                        disabled={page >= data.meta.last_page}
                        onClick={() => setPage(page + 1)}
                    >
                        {t('next')}
                    </button>
                </nav>
            )}
        </section>
    );
}
