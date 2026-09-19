import { useEffect, useState, type FormEvent } from 'react';
import { useTranslation } from 'react-i18next';
import {
    api,
    errorMessage,
    type Organization,
    type Page,
    type ScheduleRule,
} from '../lib/api';

type SchedulePage = Page<ScheduleRule> & {
    meta: { today: string; timezone: string };
};
export function GroupSchedule({
    organization,
    groupId,
    archived,
    onChanged,
}: {
    organization: Organization;
    groupId: string;
    archived: boolean;
    onChanged: () => void;
}) {
    const { t } = useTranslation();
    const [result, setResult] = useState<SchedulePage | null>(null);
    const [page, setPage] = useState(1);
    const [revision, setRevision] = useState(0);
    const [editing, setEditing] = useState<{
        mode: 'add' | 'replace' | 'close';
        rule?: ScheduleRule;
    } | null>(null);
    const [error, setError] = useState('');
    const [busy, setBusy] = useState(false);
    const base = `/api/v1/organizations/${organization.id}/groups/${groupId}/schedule`;
    const view = organization.permissions.includes('schedule.view');
    const manage =
        organization.permissions.includes('schedule.manage') && !archived;
    useEffect(() => {
        if (!view) return;
        const controller = new AbortController();
        api.get<SchedulePage>(`${base}?page=${page}`, controller.signal)
            .then(setResult)
            .catch((err: unknown) => {
                if (!controller.signal.aborted) {
                    setResult(null);
                    setError(errorMessage(err));
                }
            });
        return () => controller.abort();
    }, [base, view, page, revision]);
    function refresh() {
        setEditing(null);
        setResult(null);
        setRevision((value) => value + 1);
        onChanged();
    }
    async function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        if (!editing) return;
        const fields = Object.fromEntries(new FormData(event.currentTarget));
        setBusy(true);
        setError('');
        try {
            if (editing.mode === 'close')
                await api.patch(`${base}/${editing.rule!.id}/close`, fields);
            else
                await api.post(
                    editing.mode === 'replace'
                        ? `${base}/${editing.rule!.id}/replace`
                        : base,
                    {
                        ...fields,
                        weekday: Number(fields.weekday),
                        effective_until: fields.effective_until || null,
                    },
                );
            refresh();
        } catch (err) {
            setError(errorMessage(err));
        } finally {
            setBusy(false);
        }
    }
    async function cancel(rule: ScheduleRule) {
        setBusy(true);
        setError('');
        try {
            await api.post(`${base}/${rule.id}/cancel`);
            refresh();
        } catch (err) {
            setError(errorMessage(err));
        } finally {
            setBusy(false);
        }
    }
    if (!view) return <p>{t('scheduleRestricted')}</p>;
    const today = result?.meta.today ?? '';
    return (
        <section className="group-schedule">
            <h3>{t('weeklySchedule')}</h3>
            <p>{t('scheduleTimezone', { timezone: organization.timezone })}</p>
            <p className="muted">{t('scheduleHistoryHint')}</p>
            {error && (
                <p role="alert" className="error">
                    {error}
                </p>
            )}
            {!result && !error && <p role="status">{t('loading')}</p>}
            {manage && result && (
                <button
                    onClick={() => {
                        setEditing({ mode: 'add' });
                        setError('');
                    }}
                >
                    {t('addSchedule')}
                </button>
            )}
            {editing && manage && (
                <form
                    className="management-form"
                    key={`${editing.mode}:${editing.rule?.id ?? 'new'}`}
                    onSubmit={(event) => void submit(event)}
                >
                    <h4>
                        {t(
                            editing.mode === 'add'
                                ? 'addSchedule'
                                : editing.mode === 'replace'
                                  ? 'replaceSchedule'
                                  : 'closeSchedule',
                        )}
                    </h4>
                    <fieldset disabled={busy}>
                        {editing.mode !== 'close' && (
                            <>
                                <label>
                                    {t('weekday')}
                                    <select
                                        name="weekday"
                                        defaultValue={
                                            editing.rule?.weekday ?? 1
                                        }
                                    >
                                        {[1, 2, 3, 4, 5, 6, 7].map((day) => (
                                            <option key={day} value={day}>
                                                {t(`weekday_${day}`)}
                                            </option>
                                        ))}
                                    </select>
                                </label>
                                <label>
                                    {t('startTime')}
                                    <input
                                        name="start_time"
                                        type="time"
                                        required
                                        defaultValue={
                                            editing.rule?.start_time ?? '18:00'
                                        }
                                    />
                                </label>
                                <label>
                                    {t('endTime')}
                                    <input
                                        name="end_time"
                                        type="time"
                                        required
                                        defaultValue={
                                            editing.rule?.end_time ?? '19:00'
                                        }
                                    />
                                </label>
                                <label>
                                    {t('effectiveFrom')}
                                    <input
                                        name="effective_from"
                                        type="date"
                                        required
                                        min={today}
                                        max="2100-12-31"
                                        defaultValue={
                                            editing.mode === 'add' ? today : ''
                                        }
                                    />
                                </label>
                            </>
                        )}
                        <label>
                            {t('effectiveUntil')}
                            <input
                                name="effective_until"
                                type="date"
                                required={editing.mode === 'close'}
                                min={today}
                                max={
                                    editing.mode === 'close'
                                        ? (editing.rule?.effective_until ??
                                          '2100-12-31')
                                        : '2100-12-31'
                                }
                                defaultValue={
                                    editing.rule?.effective_until ?? ''
                                }
                            />
                        </label>
                        <p className="muted">
                            {t(
                                editing.mode === 'replace'
                                    ? 'replacementHint'
                                    : 'scheduleDatesHint',
                            )}
                        </p>
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
            )}
            {result && (
                <>
                    <div className="record-list">
                        {!result.data.length && <p>{t('noScheduleRules')}</p>}
                        {result.data.map((rule) => (
                            <article className="record-row" key={rule.id}>
                                <div>
                                    <h4>
                                        {t(`weekday_${rule.weekday}`)} ·{' '}
                                        {rule.start_time}–{rule.end_time}
                                    </h4>
                                    <p>
                                        {rule.effective_from} →{' '}
                                        {rule.effective_until ?? t('openEnded')}
                                    </p>
                                    {rule.canceled ? (
                                        <p>{t('scheduleCanceled')}</p>
                                    ) : rule.effective_until &&
                                      rule.effective_until < today ? (
                                        <p>{t('scheduleEnded')}</p>
                                    ) : (
                                        <p>{t('scheduleRetained')}</p>
                                    )}
                                </div>
                                {manage &&
                                    !rule.canceled &&
                                    (!rule.effective_until ||
                                        rule.effective_until >= today) && (
                                        <div className="form-actions">
                                            <button
                                                disabled={busy}
                                                onClick={() => {
                                                    setEditing({
                                                        mode: 'replace',
                                                        rule,
                                                    });
                                                    setError('');
                                                }}
                                            >
                                                {t('replaceSchedule')}
                                            </button>
                                            <button
                                                disabled={busy}
                                                onClick={() => {
                                                    setEditing({
                                                        mode: 'close',
                                                        rule,
                                                    });
                                                    setError('');
                                                }}
                                            >
                                                {t('closeSchedule')}
                                            </button>
                                            {rule.effective_from > today && (
                                                <button
                                                    disabled={busy}
                                                    onClick={() =>
                                                        void cancel(rule)
                                                    }
                                                >
                                                    {t('cancelFutureSchedule')}
                                                </button>
                                            )}
                                        </div>
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
            )}
        </section>
    );
}
