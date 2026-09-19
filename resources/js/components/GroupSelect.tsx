import { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { api, errorMessage, type Page } from '../lib/api';

// Bounded, paginated choices. Trainer choices are reloaded when the location changes.
export function GroupSelect({
    organizationId,
    kind,
    name,
    label,
    value,
    onChange,
    locationId,
    required = false,
    current,
}: {
    organizationId: string;
    kind: 'locations' | 'trainers';
    name: string;
    label: string;
    value: string;
    onChange: (value: string) => void;
    locationId?: string;
    required?: boolean;
    current?: { id: string; name: string };
}) {
    const { t } = useTranslation();
    const [items, setItems] = useState<{ id: string; name: string }[]>([]);
    const [page, setPage] = useState(1);
    const [last, setLast] = useState(1);
    const [error, setError] = useState('');
    useEffect(() => {
        const controller = new AbortController();
        const base = `/api/v1/organizations/${organizationId}/${kind}`;
        const request =
            kind === 'locations'
                ? api.get<Page<{ id: string; name: string; status: string }>>(
                      `${base}?page=${page}&per_page=100`,
                      controller.signal,
                  )
                : api.post<
                      Page<{ id: string; display_name: string; status: string }>
                  >(
                      `${base}/search`,
                      {
                          page,
                          per_page: 100,
                          status: 'active',
                          ...(locationId ? { location_id: locationId } : {}),
                      },
                      controller.signal,
                  );
        request
            .then((result) => {
                const choices = result.data
                    .filter((item) => item.status === 'active')
                    .map((item) => ({
                        id: item.id,
                        name: 'name' in item ? item.name : item.display_name,
                    }));
                setItems((prev) =>
                    page === 1 ? choices : [...prev, ...choices],
                );
                setLast(result.meta.last_page);
                setError('');
            })
            .catch((err: unknown) => {
                if (!controller.signal.aborted) setError(errorMessage(err));
            });
        return () => controller.abort();
    }, [organizationId, kind, locationId, page]);
    return (
        <div>
            <label>
                {label}
                <select
                    name={name}
                    value={value}
                    onChange={(event) => onChange(event.target.value)}
                    required={required}
                >
                    <option value="">
                        {t(
                            required
                                ? 'chooseLocation'
                                : kind === 'trainers' &&
                                    name === 'primary_trainer_id'
                                  ? 'trainerUnassigned'
                                  : 'all',
                        )}
                    </option>
                    {current &&
                        !items.some((item) => item.id === current.id) && (
                            <option value={current.id} disabled>
                                {current.name} ({t('currentAssignment')})
                            </option>
                        )}
                    {items.map((item) => (
                        <option key={item.id} value={item.id}>
                            {item.name}
                        </option>
                    ))}
                </select>
            </label>
            {page < last && (
                <button type="button" onClick={() => setPage(page + 1)}>
                    {t('loadMoreChoices')}
                </button>
            )}
            {error && <p role="alert">{error}</p>}
        </div>
    );
}
