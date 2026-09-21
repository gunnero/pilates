import { useEffect, useState, type FormEvent } from 'react';
import { useTranslation } from 'react-i18next';
import { api, errorMessage, type Organization, type Page } from '../lib/api';
type Member = { id: string; display_name: string };
type Reservation = {
    id: string;
    member: Member;
    status: string;
    source: string;
};
export function Reservations({
    organization,
    sessionId,
    onChanged,
}: {
    organization: Organization;
    sessionId: string;
    onChanged: () => void;
}) {
    const { t } = useTranslation();
    const base = `/api/v1/organizations/${organization.id}`;
    const [rows, setRows] = useState<Reservation[]>([]);
    const [queue, setQueue] = useState<Reservation[]>([]);
    const [members, setMembers] = useState<Member[]>([]);
    const [error, setError] = useState('');
    const [notice, setNotice] = useState('');
    const [revision, setRevision] = useState(0);
    const [busy, setBusy] = useState(false);
    const [page, setPage] = useState(1);
    const [last, setLast] = useState(1);
    const [queuePage, setQueuePage] = useState(1);
    const [queueLast, setQueueLast] = useState(1);
    const can = (p: string) => organization.permissions.includes(p);
    const view = can('reservations.view');
    const waiting = can('waiting_list.view');
    useEffect(() => {
        let live = true;
        if (view)
            void api
                .get<Page<Reservation>>(
                    `${base}/sessions/${sessionId}/reservations?page=${page}`,
                )
                .then((r) => {
                    if (live) {
                        setRows(r.data);
                        setLast(r.meta.last_page);
                    }
                })
                .catch((e) => {
                    if (live) setError(errorMessage(e));
                });
        if (waiting)
            void api
                .get<Page<Reservation>>(
                    `${base}/sessions/${sessionId}/waiting-list?per_page=100&page=${queuePage}`,
                )
                .then((r) => {
                    if (live) {
                        setQueue(r.data);
                        setQueueLast(r.meta.last_page);
                    }
                })
                .catch((e) => {
                    if (live) setError(errorMessage(e));
                });
        return () => {
            live = false;
        };
    }, [base, sessionId, revision, view, waiting, page, queuePage]);
    async function search(e: FormEvent<HTMLFormElement>) {
        e.preventDefault();
        setError('');
        setMembers([]);
        try {
            const r = await api.post<{ data: Member[] }>(
                `${base}/sessions/${sessionId}/member-lookup`,
                { search: String(new FormData(e.currentTarget).get('search')) },
            );
            setMembers(r.data);
        } catch (e) {
            setError(errorMessage(e));
        }
    }
    async function reserve(m: Member) {
        setBusy(true);
        setError('');
        try {
            const r = await api.post<{ data: { outcome: string } }>(
                `${base}/sessions/${sessionId}/reservations`,
                {
                    member_id: m.id,
                    allow_waiting: can('waiting_list.manage') && waiting,
                },
            );
            setNotice(t(`booking_${r.data.outcome}`));
            setRevision((v) => v + 1);
            onChanged();
        } catch (e) {
            setError(errorMessage(e));
        } finally {
            setBusy(false);
        }
    }
    async function cancel(r: Reservation, isQueue: boolean) {
        if (
            !window.confirm(
                t(
                    r.source === 'permanent_spot'
                        ? 'bookingReleaseConfirm'
                        : 'bookingCancelConfirm',
                ),
            )
        )
            return;
        setBusy(true);
        setError('');
        try {
            await api.post(
                `${base}/${isQueue ? 'waiting-list' : 'reservations'}/${r.id}/cancel`,
                {},
            );
            setRevision((v) => v + 1);
            onChanged();
        } catch (e) {
            setError(errorMessage(e));
        } finally {
            setBusy(false);
        }
    }
    if (!view && !waiting) return null;
    return (
        <section>
            {error && <p role="alert">{error}</p>}
            {notice && <p role="status">{notice}</p>}
            {view && (
                <>
                    <h3>{t('bookingReservations')}</h3>
                    {rows.length === 0 && <p>{t('bookingEmpty')}</p>}
                    {rows.map((r) => (
                        <div className="record-row" key={r.id}>
                            <span>
                                {r.member.display_name} ·{' '}
                                {t(`bookingStatus_${r.status}`)}
                            </span>
                            {r.status === 'active' &&
                                can('reservations.cancel') && (
                                    <button
                                        disabled={busy}
                                        onClick={() => void cancel(r, false)}
                                    >
                                        {t(
                                            r.source === 'permanent_spot'
                                                ? 'bookingRelease'
                                                : 'bookingCancel',
                                        )}
                                    </button>
                                )}
                        </div>
                    ))}
                    <div className="pagination">
                        <button
                            disabled={page === 1}
                            onClick={() => setPage(page - 1)}
                        >
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
                </>
            )}
            {waiting && (
                <>
                    <h3>{t('bookingWaiting')}</h3>
                    <ol start={(queuePage - 1) * 100 + 1}>
                        {queue.map((r) => (
                            <li key={r.id}>
                                {r.member.display_name}{' '}
                                {can('waiting_list.manage') && (
                                    <button
                                        disabled={busy}
                                        onClick={() => void cancel(r, true)}
                                    >
                                        {t('bookingCancel')}
                                    </button>
                                )}
                            </li>
                        ))}
                    </ol>
                    {queue.length === 0 && <p>{t('bookingEmpty')}</p>}
                    <div className="pagination">
                        <button
                            disabled={queuePage === 1}
                            onClick={() => setQueuePage(queuePage - 1)}
                        >
                            {t('previous')}
                        </button>
                        <span>
                            {queuePage} / {queueLast}
                        </span>
                        <button
                            disabled={queuePage >= queueLast}
                            onClick={() => setQueuePage(queuePage + 1)}
                        >
                            {t('next')}
                        </button>
                    </div>
                </>
            )}
            {can('reservations.manage') && (
                <>
                    <h3>{t('bookingAddMember')}</h3>
                    <form onSubmit={(e) => void search(e)}>
                        <label>
                            {t('search')}
                            <input
                                name="search"
                                minLength={2}
                                maxLength={100}
                                required
                            />
                        </label>
                        <button>{t('search')}</button>
                    </form>
                    {members.map((m) => (
                        <div className="record-row" key={m.id}>
                            <span>{m.display_name}</span>
                            <button
                                disabled={busy}
                                onClick={() => void reserve(m)}
                            >
                                {t('bookingReserve')}
                            </button>
                        </div>
                    ))}
                </>
            )}
        </section>
    );
}
