import { useEffect, useState, type FormEvent } from 'react';
import { useTranslation } from 'react-i18next';
import { api, errorMessage } from '../lib/api';

type Value = boolean | number | null;
type Definition = {
    type: 'boolean' | 'integer';
    default: boolean | number;
    min: number | null;
    max: number | null;
    category: string;
};
export type Settings = {
    effective: Record<string, boolean | number>;
    overrides: Record<string, Value>;
    catalog: Record<string, Definition>;
};
const categories = [
    'reservations',
    'attendance',
    'notifications',
    'member_app',
];
export function StudioPolicies({
    organization,
    manage,
}: {
    organization: string;
    manage: boolean;
}) {
    const { t } = useTranslation();
    const [data, setData] = useState<Settings | null>(null);
    const [patch, setPatch] = useState<Record<string, Value>>({});
    const [error, setError] = useState('');
    const [busy, setBusy] = useState(false);
    const [confirm, setConfirm] = useState(false);
    const [saved, setSaved] = useState(false);
    const path = `/api/v1/organizations/${organization}/settings`;
    useEffect(() => {
        const controller = new AbortController();
        api.get<{ data: Settings }>(path, controller.signal)
            .then((r) => setData(r.data))
            .catch((e: unknown) => {
                if (!controller.signal.aborted) setError(errorMessage(e));
            });
        return () => controller.abort();
    }, [path]);
    function submit(event: FormEvent) {
        event.preventDefault();
        setSaved(false);
        setConfirm(true);
    }
    async function save() {
        setBusy(true);
        setError('');
        try {
            const response = await api.patch<{ data: Settings }>(path, patch);
            setData(response.data);
            setPatch({});
            setSaved(true);
            setConfirm(false);
        } catch (e) {
            setError(errorMessage(e));
        } finally {
            setBusy(false);
        }
    }
    return (
        <section aria-label={t('policy.title')}>
            <h2>{t('policy.title')}</h2>
            <p>{t('policy.help')}</p>
            {error && <p role="alert">{error}</p>}
            {!data && !error && <p role="status">{t('loading')}</p>}
            {data && (
                <form className="management-form" onSubmit={submit}>
                    {categories.map((category) => (
                        <fieldset
                            key={category}
                            disabled={!manage || busy || confirm}
                        >
                            <legend>{t(`policy.${category}`)}</legend>
                            {Object.entries(data.catalog)
                                .filter(
                                    ([, definition]) =>
                                        definition.category === category,
                                )
                                .map(([key, definition]) => {
                                    const override = Object.hasOwn(patch, key)
                                        ? patch[key]
                                        : data.overrides[key];
                                    const effective =
                                        override ?? definition.default;
                                    return (
                                        <div key={key} className="policy-field">
                                            <label>
                                                {t(`policy.${key}`)}
                                                {definition.type ===
                                                'boolean' ? (
                                                    <select
                                                        value={
                                                            override === null
                                                                ? 'default'
                                                                : String(
                                                                      effective,
                                                                  )
                                                        }
                                                        onChange={(e) =>
                                                            setPatch({
                                                                ...patch,
                                                                [key]:
                                                                    e.target
                                                                        .value ===
                                                                    'default'
                                                                        ? null
                                                                        : e
                                                                              .target
                                                                              .value ===
                                                                          'true',
                                                            })
                                                        }
                                                    >
                                                        <option value="default">
                                                            {t(
                                                                'policy.default',
                                                            )}{' '}
                                                            (
                                                            {t(
                                                                definition.default
                                                                    ? 'policy.on'
                                                                    : 'policy.off',
                                                            )}
                                                            )
                                                        </option>
                                                        <option value="true">
                                                            {t('policy.on')}
                                                        </option>
                                                        <option value="false">
                                                            {t('policy.off')}
                                                        </option>
                                                    </select>
                                                ) : (
                                                    <input
                                                        type="number"
                                                        min={
                                                            definition.min ??
                                                            undefined
                                                        }
                                                        max={
                                                            definition.max ??
                                                            undefined
                                                        }
                                                        step={1}
                                                        required
                                                        value={Number(
                                                            effective,
                                                        )}
                                                        onChange={(e) =>
                                                            setPatch({
                                                                ...patch,
                                                                [key]: e.target
                                                                    .valueAsNumber,
                                                            })
                                                        }
                                                    />
                                                )}
                                            </label>
                                            <small>
                                                {t('policy.default')}:{' '}
                                                {typeof definition.default ===
                                                'boolean'
                                                    ? t(
                                                          definition.default
                                                              ? 'policy.on'
                                                              : 'policy.off',
                                                      )
                                                    : definition.default}{' '}
                                                ·{' '}
                                                {t(
                                                    override === null
                                                        ? 'policy.inherited'
                                                        : 'policy.override',
                                                )}
                                            </small>
                                            {definition.type === 'integer' && (
                                                <button
                                                    type="button"
                                                    onClick={() =>
                                                        setPatch({
                                                            ...patch,
                                                            [key]: null,
                                                        })
                                                    }
                                                >
                                                    {t('policy.reset')}
                                                </button>
                                            )}
                                        </div>
                                    );
                                })}
                        </fieldset>
                    ))}
                    {manage && !confirm && (
                        <button
                            className="primary"
                            disabled={busy || Object.keys(patch).length === 0}
                        >
                            {t('save')}
                        </button>
                    )}
                    {confirm && (
                        <div role="group" aria-label={t('policy.confirm')}>
                            <p>{t('policy.confirm')}</p>
                            <button
                                type="button"
                                className="primary"
                                disabled={busy}
                                onClick={() => void save()}
                            >
                                {t('policy.apply')}
                            </button>
                            <button
                                type="button"
                                disabled={busy}
                                onClick={() => setConfirm(false)}
                            >
                                {t('cancel')}
                            </button>
                        </div>
                    )}
                    {saved && <p role="status">{t('saved')}</p>}
                </form>
            )}
        </section>
    );
}
