import { parseMoney, money } from '../lib/money';
import { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { api, errorMessage, type Organization, type Page } from '../lib/api';
type Member = { id: string; display_name: string };
type Plan = {
    id: string;
    name: string;
    description: string | null;
    price_minor?: number;
    currency?: string;
    duration_unit: string;
    duration_count: number;
    status: string;
};
type Membership = {
    id: string;
    member: Member;
    plan_name: string;
    starts_on: string;
    ends_on: string;
    status: string;
    agreed_price_minor?: number;
    net_paid_minor?: number;
    balance_minor?: number;
    payment_status?: string;
};
type Payment = {
    id: string;
    member: Member;
    amount_minor: number;
    paid_at: string;
    recorded_by: { name: string };
    status: string;
    note?: string | null;
    reversal: null | {
        reason: string;
        recorded_by: { name: string };
        created_at: string;
    };
};
function Pager({
    page,
    last,
    onChange,
}: {
    page: number;
    last: number;
    onChange: (page: number) => void;
}) {
    const { t } = useTranslation();
    return (
        <div className="pagination">
            <button disabled={page <= 1} onClick={() => onChange(page - 1)}>
                {t('previous')}
            </button>
            <span>
                {page} / {last}
            </span>
            <button disabled={page >= last} onClick={() => onChange(page + 1)}>
                {t('next')}
            </button>
        </div>
    );
}
export function Finance({
    organization,
    memberId,
}: {
    organization: Organization;
    memberId?: string;
}) {
    if (
        !organization.permissions.some((p) =>
            ['memberships.view', 'payments.view'].includes(p),
        )
    )
        return null;
    return (
        <FinanceContent
            key={`${organization.id}:${memberId ?? ''}`}
            organization={organization}
            memberId={memberId}
        />
    );
}
function FinanceContent({
    organization,
    memberId,
}: {
    organization: Organization;
    memberId?: string;
}) {
    const { t } = useTranslation();
    const base = `/api/v1/organizations/${organization.id}`;
    const financial = organization.permissions.includes('payments.view');
    const manage =
        organization.permissions.includes('memberships.manage') &&
        organization.permissions.includes('memberships.view');
    const [tab, setTab] = useState('memberships');
    const [page, setPage] = useState(1);
    const [state, setState] = useState('all');
    const [paymentState, setPaymentState] = useState('all');
    const [rows, setRows] = useState<Page<Membership> | null>(null);
    const [selected, setSelected] = useState<Membership | null>(null);
    const [adding, setAdding] = useState(false);
    const [version, setVersion] = useState(0);
    const [error, setError] = useState('');
    useEffect(() => {
        const c = new AbortController();
        void api
            .get<Page<Membership>>(
                `${base}/memberships?page=${page}&state=${state}&payment_state=${paymentState}${memberId ? `&member_id=${memberId}` : ''}`,
                c.signal,
            )
            .then((r) => {
                setRows(r);
                setError('');
            })
            .catch((e) => {
                if (!c.signal.aborted) setError(errorMessage(e));
            });
        return () => c.abort();
    }, [base, memberId, page, state, paymentState, version]);
    function changed() {
        setVersion((v) => v + 1);
        setAdding(false);
    }
    return (
        <section className="finance">
            <h2>{t('memberships')}</h2>
            {!memberId && (
                <div className="actions">
                    <button onClick={() => setTab('memberships')}>
                        {t('memberships')}
                    </button>
                    <button onClick={() => setTab('plans')}>
                        {t('financePlans')}
                    </button>
                </div>
            )}
            {error && <p role="alert">{error}</p>}
            {tab === 'plans' ? (
                <Plans organization={organization} />
            ) : (
                <>
                    <div className="form-grid">
                        <label>
                            {t('financeValidity')}
                            <select
                                value={state}
                                onChange={(e) => {
                                    setState(e.target.value);
                                    setPage(1);
                                }}
                            >
                                {[
                                    'all',
                                    'active',
                                    'expiring',
                                    'expired',
                                    'upcoming',
                                    'cancelled',
                                ].map((s) => (
                                    <option key={s} value={s}>
                                        {t(`financeState.${s}`)}
                                    </option>
                                ))}
                            </select>
                        </label>
                        {financial && (
                            <label>
                                {t('financePaymentStatus')}
                                <select
                                    value={paymentState}
                                    onChange={(e) => {
                                        setPaymentState(e.target.value);
                                        setPage(1);
                                    }}
                                >
                                    {[
                                        'all',
                                        'unpaid',
                                        'partially_paid',
                                        'paid',
                                        'overpaid',
                                    ].map((s) => (
                                        <option key={s} value={s}>
                                            {t(`financeState.${s}`)}
                                        </option>
                                    ))}
                                </select>
                            </label>
                        )}
                    </div>
                    {manage && (
                        <button onClick={() => setAdding(!adding)}>
                            {t('financeAssign')}
                        </button>
                    )}
                    {adding && (
                        <Assign
                            organization={organization}
                            memberId={memberId}
                            onDone={changed}
                        />
                    )}
                    {rows?.data.length === 0 && <p>{t('financeEmpty')}</p>}
                    {!rows && !error && <p role="status">{t('loading')}</p>}
                    <div className="finance-list">
                        {rows?.data.map((m) => (
                            <article key={m.id} className="finance-card">
                                <h3>{m.member.display_name}</h3>
                                <p>
                                    {m.plan_name} · {m.starts_on} – {m.ends_on}
                                </p>
                                <p>
                                    {t(`financeState.${m.status}`)}
                                    {financial &&
                                        m.payment_status &&
                                        ` · ${t(`financeState.${m.payment_status}`)}`}
                                </p>
                                {financial && m.balance_minor !== undefined && (
                                    <p>
                                        {t('financeBalance')}:{' '}
                                        {money(m.balance_minor)}
                                    </p>
                                )}
                                <button onClick={() => setSelected(m)}>
                                    {t('financeDetails')}
                                </button>
                            </article>
                        ))}
                    </div>
                    {rows && (
                        <Pager
                            page={page}
                            last={rows.meta.last_page}
                            onChange={setPage}
                        />
                    )}
                    {selected && (
                        <MembershipDetail
                            key={selected.id}
                            initial={selected}
                            organization={organization}
                            onChanged={() => setVersion((v) => v + 1)}
                            onClose={() => setSelected(null)}
                        />
                    )}
                </>
            )}
        </section>
    );
}
function Plans({ organization }: { organization: Organization }) {
    const { t } = useTranslation();
    const base = `/api/v1/organizations/${organization.id}/membership-plans`;
    const editable = [
        'memberships.view',
        'memberships.manage',
        'payments.view',
    ].every((p) => organization.permissions.includes(p));
    const [rows, setRows] = useState<Page<Plan> | null>(null);
    const [page, setPage] = useState(1);
    const [version, setVersion] = useState(0);
    const [form, setForm] = useState<Plan | null>(null);
    const [price, setPrice] = useState('');
    const [error, setError] = useState('');
    const [busy, setBusy] = useState(false);
    useEffect(() => {
        const c = new AbortController();
        void api
            .get<Page<Plan>>(`${base}?page=${page}`, c.signal)
            .then(setRows)
            .catch((e) => {
                if (!c.signal.aborted) setError(errorMessage(e));
            });
        return () => c.abort();
    }, [base, page, version]);
    async function save(e: React.SubmitEvent<HTMLFormElement>) {
        e.preventDefault();
        if (!form) return;
        const amount = parseMoney(price);
        if (amount === null) {
            setError(t('financeInvalidAmount'));
            return;
        }
        setBusy(true);
        setError('');
        try {
            const data = {
                name: form.name,
                description: form.description,
                price_minor: amount,
                duration_unit: form.duration_unit,
                duration_count: form.duration_count,
                status: form.status,
            };
            if (form.id) await api.patch(`${base}/${form.id}`, data);
            else await api.post(base, data);
            setForm(null);
            setVersion((v) => v + 1);
        } catch (e) {
            setError(errorMessage(e));
        } finally {
            setBusy(false);
        }
    }
    return (
        <section>
            <h3>{t('financePlans')}</h3>
            {error && <p role="alert">{error}</p>}
            {editable && (
                <button
                    onClick={() => {
                        setForm({
                            id: '',
                            name: '',
                            description: '',
                            duration_unit: 'months',
                            duration_count: 1,
                            status: 'active',
                        });
                        setPrice('');
                    }}
                >
                    {t('financeNewPlan')}
                </button>
            )}
            {form && (
                <form className="form-grid" onSubmit={(e) => void save(e)}>
                    <label>
                        {t('financePlanName')}
                        <input
                            required
                            maxLength={160}
                            value={form.name}
                            onChange={(e) =>
                                setForm({ ...form, name: e.target.value })
                            }
                        />
                    </label>
                    <label>
                        {t('financeDescription')}
                        <textarea
                            maxLength={1000}
                            value={form.description ?? ''}
                            onChange={(e) =>
                                setForm({
                                    ...form,
                                    description: e.target.value,
                                })
                            }
                        />
                    </label>
                    <label>
                        {t('financePrice')}
                        <input
                            inputMode="decimal"
                            required
                            value={price}
                            onChange={(e) => setPrice(e.target.value)}
                        />
                    </label>
                    <label>
                        {t('financeDuration')}
                        <input
                            type="number"
                            required
                            min={1}
                            max={form.duration_unit === 'months' ? 36 : 1095}
                            value={form.duration_count}
                            onChange={(e) =>
                                setForm({
                                    ...form,
                                    duration_count: Number(e.target.value),
                                })
                            }
                        />
                    </label>
                    <label>
                        {t('financeUnit')}
                        <select
                            value={form.duration_unit}
                            onChange={(e) =>
                                setForm({
                                    ...form,
                                    duration_unit: e.target.value,
                                })
                            }
                        >
                            <option value="months">{t('financeMonths')}</option>
                            <option value="days">{t('financeDays')}</option>
                        </select>
                    </label>
                    <label>
                        {t('financeStatus')}
                        <select
                            value={form.status}
                            onChange={(e) =>
                                setForm({ ...form, status: e.target.value })
                            }
                        >
                            {['active', 'inactive', 'archived'].map((s) => (
                                <option key={s} value={s}>
                                    {t(`financeState.${s}`)}
                                </option>
                            ))}
                        </select>
                    </label>
                    <button disabled={busy}>{t('save')}</button>
                    <button type="button" onClick={() => setForm(null)}>
                        {t('cancel')}
                    </button>
                </form>
            )}
            {rows?.data.map((p) => (
                <article className="finance-card" key={p.id}>
                    <h4>{p.name}</h4>
                    <p>{p.description}</p>
                    <p>
                        {p.duration_count}{' '}
                        {t(
                            p.duration_unit === 'months'
                                ? 'financeMonths'
                                : 'financeDays',
                        )}{' '}
                        · {t(`financeState.${p.status}`)}{' '}
                        {p.price_minor !== undefined && money(p.price_minor)}
                    </p>
                    {editable && p.status !== 'archived' && (
                        <button
                            onClick={() => {
                                setForm(p);
                                setPrice(
                                    p.price_minor === undefined
                                        ? ''
                                        : money(p.price_minor).replace(
                                              ' MKD',
                                              '',
                                          ),
                                );
                            }}
                        >
                            {t('edit')}
                        </button>
                    )}
                </article>
            ))}
            {rows && (
                <Pager
                    page={page}
                    last={rows.meta.last_page}
                    onChange={setPage}
                />
            )}
        </section>
    );
}
function Assign({
    organization,
    memberId,
    onDone,
}: {
    organization: Organization;
    memberId?: string;
    onDone: () => void;
}) {
    const { t } = useTranslation();
    const base = `/api/v1/organizations/${organization.id}`;
    const [plans, setPlans] = useState<Plan[]>([]);
    const [planPage, setPlanPage] = useState(1);
    const [last, setLast] = useState(1);
    const [plan, setPlan] = useState('');
    const [start, setStart] = useState(
        new Intl.DateTimeFormat('en-CA', {
            timeZone: organization.timezone,
        }).format(new Date()),
    );
    const [end, setEnd] = useState('');
    const [price, setPrice] = useState<number | undefined>();
    const [search, setSearch] = useState('');
    const [members, setMembers] = useState<Member[]>([]);
    const [member, setMember] = useState(memberId ?? '');
    const [error, setError] = useState('');
    const [busy, setBusy] = useState(false);
    useEffect(() => {
        const c = new AbortController();
        void api
            .get<Page<Plan>>(
                `${base}/membership-plans?status=active&page=${planPage}`,
                c.signal,
            )
            .then((r) => {
                setPlans(r.data);
                setLast(r.meta.last_page);
            })
            .catch((e) => {
                if (!c.signal.aborted) setError(errorMessage(e));
            });
        return () => c.abort();
    }, [base, planPage]);
    useEffect(() => {
        if (!plan || !start) return;
        const c = new AbortController();
        void api
            .post<{ data: { ends_on: string; plan: Plan } }>(
                `${base}/membership-plans/${plan}/preview`,
                { starts_on: start },
                c.signal,
            )
            .then((r) => {
                setEnd(r.data.ends_on);
                setPrice(r.data.plan.price_minor);
                setError('');
            })
            .catch((e) => {
                if (!c.signal.aborted) setError(errorMessage(e));
            });
        return () => c.abort();
    }, [base, plan, start]);
    async function lookup(e: React.SubmitEvent<HTMLFormElement>) {
        e.preventDefault();
        setError('');
        try {
            setMembers(
                (
                    await api.post<{ data: Member[] }>(
                        `${base}/finance/member-lookup`,
                        { search },
                    )
                ).data,
            );
        } catch (e) {
            setError(errorMessage(e));
        }
    }
    async function assign() {
        setBusy(true);
        setError('');
        try {
            await api.post(`${base}/memberships`, {
                member_id: member,
                plan_id: plan,
                starts_on: start,
            });
            onDone();
        } catch (e) {
            setError(errorMessage(e));
        } finally {
            setBusy(false);
        }
    }
    return (
        <section className="finance-card">
            <h3>{t('financeAssign')}</h3>
            {error && <p role="alert">{error}</p>}
            {!memberId && (
                <>
                    <form onSubmit={(e) => void lookup(e)}>
                        <label>
                            {t('financeFindMember')}
                            <input
                                required
                                minLength={2}
                                maxLength={100}
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                            />
                        </label>
                        <button>{t('search')}</button>
                    </form>
                    <label>
                        {t('financeMember')}
                        <select
                            value={member}
                            onChange={(e) => setMember(e.target.value)}
                        >
                            <option value="">{t('choose')}</option>
                            {members.map((m) => (
                                <option key={m.id} value={m.id}>
                                    {m.display_name}
                                </option>
                            ))}
                        </select>
                    </label>
                </>
            )}
            <label>
                {t('financePlan')}
                <select
                    value={plan}
                    onChange={(e) => {
                        setPlan(e.target.value);
                        setEnd('');
                    }}
                >
                    <option value="">{t('choose')}</option>
                    {plans.map((p) => (
                        <option key={p.id} value={p.id}>
                            {p.name}
                        </option>
                    ))}
                </select>
            </label>
            {last > 1 && (
                <Pager
                    page={planPage}
                    last={last}
                    onChange={(p) => {
                        setPlanPage(p);
                        setPlan('');
                        setEnd('');
                    }}
                />
            )}
            <label>
                {t('financeStart')}
                <input
                    type="date"
                    required
                    min="2000-01-01"
                    max="2100-12-31"
                    value={start}
                    onChange={(e) => {
                        setStart(e.target.value);
                        setEnd('');
                    }}
                />
            </label>
            {end && (
                <p>
                    {t('financePreview')}: {start} – {end}{' '}
                    {price !== undefined && money(price)}
                </p>
            )}
            <button
                disabled={busy || !member || !plan || !end}
                onClick={() => void assign()}
            >
                {t('financeConfirmMembership')}
            </button>
        </section>
    );
}
function MembershipDetail({
    initial,
    organization,
    onChanged,
    onClose,
}: {
    initial: Membership;
    organization: Organization;
    onChanged: () => void;
    onClose: () => void;
}) {
    const { t } = useTranslation();
    const base = `/api/v1/organizations/${organization.id}`;
    const financial = organization.permissions.includes('payments.view');
    const record =
        financial && organization.permissions.includes('payments.record');
    const correct =
        financial && organization.permissions.includes('payments.correct');
    const manage =
        organization.permissions.includes('memberships.manage') &&
        organization.permissions.includes('memberships.view');
    const [m, setM] = useState(initial);
    const [payments, setPayments] = useState<Page<Payment> | null>(null);
    const [page, setPage] = useState(1);
    const [version, setVersion] = useState(0);
    const [error, setError] = useState('');
    const [busy, setBusy] = useState(false);
    const [paying, setPaying] = useState(false);
    const [amount, setAmount] = useState('');
    const [paidAt, setPaidAt] = useState(new Date().toISOString().slice(0, 16));
    const [note, setNote] = useState('');
    const [confirmation, setConfirmation] = useState<{
        amount_minor: number;
        paid_at: string;
        note: string;
        request_id: string;
    } | null>(null);
    const [reversing, setReversing] = useState<Payment | null>(null);
    const [reason, setReason] = useState('');
    const [cancelling, setCancelling] = useState(false);
    const [cancelReason, setCancelReason] = useState('');
    useEffect(() => {
        const c = new AbortController();
        void api
            .get<{ data: Membership }>(
                `${base}/memberships/${initial.id}`,
                c.signal,
            )
            .then((r) => setM(r.data))
            .catch((e) => {
                if (!c.signal.aborted) setError(errorMessage(e));
            });
        if (financial)
            void api
                .get<Page<Payment>>(
                    `${base}/cash-payments?membership_id=${initial.id}&page=${page}`,
                    c.signal,
                )
                .then(setPayments)
                .catch((e) => {
                    if (!c.signal.aborted) setError(errorMessage(e));
                });
        return () => c.abort();
    }, [base, initial.id, financial, page, version]);
    async function mutate(path: string, body: unknown) {
        setBusy(true);
        setError('');
        try {
            await api.post(`${base}/${path}`, body);
            setConfirmation(null);
            setPaying(false);
            setReversing(null);
            setCancelling(false);
            setVersion((v) => v + 1);
            onChanged();
        } catch (e) {
            setError(errorMessage(e));
        } finally {
            setBusy(false);
        }
    }
    function prepare(e: React.SubmitEvent<HTMLFormElement>) {
        e.preventDefault();
        const n = parseMoney(amount);
        if (n === null || n < 1) {
            setError(t('financeInvalidAmount'));
            return;
        }
        setError('');
        setConfirmation({
            amount_minor: n,
            paid_at: new Date(`${paidAt}:00Z`).toISOString(),
            note,
            request_id: crypto.randomUUID(),
        });
    }
    return (
        <section className="finance-detail" aria-label={t('financeDetails')}>
            <button onClick={onClose}>{t('close')}</button>
            <h3>
                {m.member.display_name} · {m.plan_name}
            </h3>
            <p>
                {m.starts_on} – {m.ends_on} · {t(`financeState.${m.status}`)}
            </p>
            {error && <p role="alert">{error}</p>}
            {financial && m.agreed_price_minor !== undefined && (
                <dl>
                    <dt>{t('financePrice')}</dt>
                    <dd>{money(m.agreed_price_minor)}</dd>
                    <dt>{t('financePaid')}</dt>
                    <dd>{money(m.net_paid_minor ?? 0)}</dd>
                    <dt>{t('financeBalance')}</dt>
                    <dd>{money(m.balance_minor ?? 0)}</dd>
                    <dt>{t('financePaymentStatus')}</dt>
                    <dd>{t(`financeState.${m.payment_status}`)}</dd>
                </dl>
            )}
            {record && m.status !== 'cancelled' && (
                <button
                    onClick={() => {
                        setPaying(!paying);
                        setConfirmation(null);
                        setAmount('');
                        setNote('');
                        setPaidAt(new Date().toISOString().slice(0, 16));
                    }}
                >
                    {t('financeRecord')}
                </button>
            )}
            {paying && !confirmation && (
                <form className="form-grid" onSubmit={prepare}>
                    <p>{t('financeCash')}</p>
                    <label>
                        {t('financeAmount')}
                        <input
                            required
                            inputMode="decimal"
                            value={amount}
                            onChange={(e) => setAmount(e.target.value)}
                        />
                    </label>
                    <label>
                        {t('financePaidAtUTC')}
                        <input
                            required
                            type="datetime-local"
                            value={paidAt}
                            onChange={(e) => setPaidAt(e.target.value)}
                        />
                    </label>
                    <label>
                        {t('financeNote')}
                        <textarea
                            maxLength={500}
                            value={note}
                            onChange={(e) => setNote(e.target.value)}
                        />
                    </label>
                    <button>{t('financeReview')}</button>
                </form>
            )}
            {confirmation && (
                <div role="group" aria-label={t('financeConfirmPayment')}>
                    <p>
                        {m.member.display_name} ·{' '}
                        {money(confirmation.amount_minor)} · {m.starts_on} –{' '}
                        {m.ends_on} · {t('financeCash')}
                    </p>
                    <button
                        disabled={busy}
                        onClick={() =>
                            void mutate('cash-payments', {
                                ...confirmation,
                                membership_id: m.id,
                            })
                        }
                    >
                        {t('financeConfirmPayment')}
                    </button>
                    <button
                        disabled={busy}
                        onClick={() => setConfirmation(null)}
                    >
                        {t('cancel')}
                    </button>
                </div>
            )}
            {manage && m.status !== 'cancelled' && (
                <button onClick={() => setCancelling(!cancelling)}>
                    {t('financeCancelMembership')}
                </button>
            )}
            {cancelling && (
                <div>
                    <p>{t('financeCancelWarning')}</p>
                    <label>
                        {t('financeReason')}
                        <select
                            value={cancelReason}
                            onChange={(e) => setCancelReason(e.target.value)}
                        >
                            <option value="">{t('choose')}</option>
                            {['mistake', 'member_request', 'other'].map((r) => (
                                <option key={r} value={r}>
                                    {t(`financeReasonCode.${r}`)}
                                </option>
                            ))}
                        </select>
                    </label>
                    <button
                        disabled={busy || !cancelReason}
                        onClick={() =>
                            void mutate(`memberships/${m.id}/cancel`, {
                                reason: cancelReason,
                            })
                        }
                    >
                        {t('financeConfirmCancellation')}
                    </button>
                </div>
            )}
            {financial && (
                <>
                    <h4>{t('financeHistory')}</h4>
                    {payments?.data.length === 0 && (
                        <p>{t('financeNoPayments')}</p>
                    )}
                    {payments?.data.map((p) => (
                        <article key={p.id} className="finance-card">
                            <strong>
                                {money(p.amount_minor)} ·{' '}
                                {t(`financeState.${p.status}`)}
                            </strong>
                            <p>
                                {p.paid_at} · {t('financeRecordedBy')}:{' '}
                                {p.recorded_by.name}
                            </p>
                            {p.reversal && (
                                <p>
                                    {t('financeReversedBy')}:{' '}
                                    {p.reversal.recorded_by.name} ·{' '}
                                    {p.reversal.created_at} ·{' '}
                                    {t(
                                        `financeReasonCode.${p.reversal.reason}`,
                                    )}
                                </p>
                            )}
                            {correct && !p.reversal && (
                                <button
                                    onClick={() => {
                                        setReversing(p);
                                        setReason('');
                                    }}
                                >
                                    {t('financeReverse')}
                                </button>
                            )}
                        </article>
                    ))}
                    {payments && (
                        <Pager
                            page={page}
                            last={payments.meta.last_page}
                            onChange={setPage}
                        />
                    )}
                </>
            )}
            {reversing && (
                <div role="group" aria-label={t('financeReverse')}>
                    <p>
                        {t('financeReverseWarning')} {m.member.display_name} ·{' '}
                        {money(reversing.amount_minor)}
                    </p>
                    <label>
                        {t('financeReason')}
                        <select
                            value={reason}
                            onChange={(e) => setReason(e.target.value)}
                        >
                            <option value="">{t('choose')}</option>
                            {[
                                'wrong_member',
                                'wrong_amount',
                                'wrong_date',
                                'duplicate',
                                'other',
                            ].map((r) => (
                                <option key={r} value={r}>
                                    {t(`financeReasonCode.${r}`)}
                                </option>
                            ))}
                        </select>
                    </label>
                    <button
                        disabled={busy || !reason}
                        onClick={() =>
                            void mutate(
                                `cash-payments/${reversing.id}/reverse`,
                                { reason },
                            )
                        }
                    >
                        {t('financeConfirmReversal')}
                    </button>
                    <button disabled={busy} onClick={() => setReversing(null)}>
                        {t('cancel')}
                    </button>
                </div>
            )}
        </section>
    );
}
