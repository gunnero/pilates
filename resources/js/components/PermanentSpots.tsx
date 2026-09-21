import { useEffect, useState, type FormEvent } from 'react';
import { useTranslation } from 'react-i18next';
import { api, errorMessage, type Organization, type Page } from '../lib/api';
type Member = { id: string; display_name: string };
type Spot = {
    id: string;
    member: Member;
    effective_from: string;
    effective_until: string | null;
    status: string;
};
export function PermanentSpots({
    organization,
    groupId,
}: {
    organization: Organization;
    groupId: string;
}) {
    const { t } = useTranslation();
    const base = `/api/v1/organizations/${organization.id}`;
    const [spots, setSpots] = useState<Spot[]>([]);
    const [members, setMembers] = useState<Member[]>([]);
    const [error, setError] = useState('');
    const [notice, setNotice] = useState('');
    const [revision, setRevision] = useState(0);
    const [busy, setBusy] = useState(false);
    const [page, setPage] = useState(1);
    const [last, setLast] = useState(1);
    const view = organization.permissions.includes('permanent_spots.view');
    const manage = organization.permissions.includes('permanent_spots.manage');
    useEffect(() => {
        let live = true;
        if (view)
            void api
                .get<Page<Spot>>(
                    `${base}/groups/${groupId}/permanent-spots?page=${page}`,
                )
                .then((r) => {
                    if (live) {
                        setSpots(r.data);
                        setLast(r.meta.last_page);
                    }
                })
                .catch((e) => {
                    if (live) setError(errorMessage(e));
                });
        return () => {
            live = false;
        };
    }, [base, groupId, view, revision, page]);
    async function search(e: FormEvent<HTMLFormElement>) {
        e.preventDefault();
        setMembers([]);
        try {
            const r = await api.post<{ data: Member[] }>(
                `${base}/groups/${groupId}/member-lookup`,
                { search: String(new FormData(e.currentTarget).get('search')) },
            );
            setMembers(r.data);
        } catch (e) {
            setError(errorMessage(e));
        }
    }
    async function save(e: FormEvent<HTMLFormElement>, spot?: Spot) {
        e.preventDefault();
        setBusy(true);
        setError('');
        const f = new FormData(e.currentTarget);
        const data = {
            effective_from: String(f.get('from')),
            effective_until: String(f.get('until')) || null,
            status: spot ? String(f.get('status')) : 'active',
            ...(!spot ? { member_id: String(f.get('member')) } : {}),
        };
        try {
            const r = spot
                ? await api.patch<{
                      meta: { synchronization: { conflicts: number } };
                  }>(`${base}/permanent-spots/${spot.id}`, data)
                : await api.post<{
                      meta: { synchronization: { conflicts: number } };
                  }>(`${base}/groups/${groupId}/permanent-spots`, data);
            setNotice(
                t(
                    r.meta.synchronization.conflicts
                        ? 'spotConflicts'
                        : 'spotSaved',
                ),
            );
            setRevision((v) => v + 1);
        } catch (e) {
            setError(errorMessage(e));
        } finally {
            setBusy(false);
        }
    }
    if (!view) return null;
    return (
        <section>
            <h3>{t('spotTitle')}</h3>
            <p>{t('spotExplanation')}</p>
            {error && <p role="alert">{error}</p>}
            {notice && <p role="status">{notice}</p>}
            {spots.length === 0 && <p>{t('bookingEmpty')}</p>}
            {spots.map((s) => (
                <article key={s.id}>
                    <strong>{s.member.display_name}</strong>
                    {manage ? (
                        <form
                            className="management-form"
                            onSubmit={(e) => void save(e, s)}
                        >
                            <label>
                                {t('spotFrom')}
                                <input
                                    name="from"
                                    type="date"
                                    required
                                    defaultValue={s.effective_from}
                                />
                            </label>
                            <label>
                                {t('spotUntil')}
                                <input
                                    name="until"
                                    type="date"
                                    defaultValue={s.effective_until ?? ''}
                                />
                            </label>
                            <label>
                                {t('status')}
                                <select name="status" defaultValue={s.status}>
                                    <option value="active">
                                        {t('bookingStatus_active')}
                                    </option>
                                    <option value="inactive">
                                        {t('spotEnded')}
                                    </option>
                                </select>
                            </label>
                            <p>{t('spotEndHint')}</p>
                            <button disabled={busy}>{t('save')}</button>
                        </form>
                    ) : (
                        <p>
                            {s.effective_from} – {s.effective_until ?? '…'} ·{' '}
                            {s.status}
                        </p>
                    )}
                </article>
            ))}
            <div className="pagination">
                <button disabled={page === 1} onClick={() => setPage(page - 1)}>
                    {t('previous')}
                </button>
                <span>
                    {page} / {last}
                </span>
                <button
                    disabled={page >= last}
                    onClick={() => setPage(page + 1)}
                >
                    {t('next')}
                </button>
            </div>
            {manage && (
                <>
                    <h4>{t('bookingAddMember')}</h4>
                    <form onSubmit={(e) => void search(e)}>
                        <label>
                            {t('search')}
                            <input
                                name="search"
                                required
                                minLength={2}
                                maxLength={100}
                            />
                        </label>
                        <button>{t('search')}</button>
                    </form>
                    {members.length > 0 && (
                        <form
                            className="management-form"
                            onSubmit={(e) => void save(e)}
                        >
                            <label>
                                {t('bookingAddMember')}
                                <select name="member">
                                    {members.map((m) => (
                                        <option key={m.id} value={m.id}>
                                            {m.display_name}
                                        </option>
                                    ))}
                                </select>
                            </label>
                            <label>
                                {t('spotFrom')}
                                <input type="date" name="from" required />
                            </label>
                            <label>
                                {t('spotUntil')}
                                <input type="date" name="until" />
                            </label>
                            <button disabled={busy}>{t('spotAdd')}</button>
                        </form>
                    )}
                </>
            )}
        </section>
    );
}
