import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import { api, errorMessage, type Organization, type Page } from '../lib/api';
type Entry = {
    id: string;
    session_id?: string;
    group_id?: string;
    effective_from?: string;
    effective_until?: string | null;
    status: string;
    session_name?: string;
    group_name?: string;
    local_date?: string;
    start_time?: string;
};
function BookingList({
    organization,
    memberId,
    spots,
}: {
    organization: Organization;
    memberId: string;
    spots: boolean;
}) {
    const { t } = useTranslation();
    const [result, setResult] = useState<Page<Entry> | null>(null);
    const [page, setPage] = useState(1);
    const [error, setError] = useState('');
    useEffect(() => {
        let live = true;
        void api
            .get<Page<Entry>>(
                `/api/v1/organizations/${organization.id}/members/${memberId}/${spots ? 'permanent-spots' : 'reservations'}?page=${page}`,
            )
            .then((r) => {
                if (live) setResult(r);
            })
            .catch((e) => {
                if (live) setError(errorMessage(e));
            });
        return () => {
            live = false;
        };
    }, [organization.id, memberId, spots, page]);
    return (
        <section>
            <h3>{t(spots ? 'spotTitle' : 'bookingUpcoming')}</h3>
            {error && <p role="alert">{error}</p>}
            {result?.data.length === 0 && <p>{t('bookingEmpty')}</p>}
            {result?.data.map((r) => (
                <p key={r.id}>
                    <Link
                        to={
                            spots
                                ? `/groups/${r.group_id}`
                                : `/schedule/${r.session_id}`
                        }
                    >
                        {spots
                            ? (r.group_name ?? t('spotTitle'))
                            : `${r.local_date ?? ''} ${r.start_time ?? ''} ${r.session_name ?? t('openSession')}`}
                    </Link>{' '}
                    {r.effective_from} {r.effective_until} · {r.status}
                </p>
            ))}
            {result && (
                <div className="pagination">
                    <button
                        disabled={page === 1}
                        onClick={() => setPage(page - 1)}
                    >
                        {t('previous')}
                    </button>
                    <span>
                        {page} / {result.meta.last_page}
                    </span>
                    <button
                        disabled={page >= result.meta.last_page}
                        onClick={() => setPage(page + 1)}
                    >
                        {t('next')}
                    </button>
                </div>
            )}
        </section>
    );
}
export function MemberBookings({
    organization,
    memberId,
}: {
    organization: Organization;
    memberId: string;
}) {
    return (
        <>
            {organization.permissions.includes('reservations.view') &&
                organization.permissions.includes('sessions.view') && (
                    <BookingList
                        organization={organization}
                        memberId={memberId}
                        spots={false}
                    />
                )}{' '}
            {organization.permissions.includes('permanent_spots.view') &&
                organization.permissions.includes('groups.view') && (
                    <BookingList
                        organization={organization}
                        memberId={memberId}
                        spots
                    />
                )}
        </>
    );
}
