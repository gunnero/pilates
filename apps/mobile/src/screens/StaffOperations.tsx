import { useEffect, useCallback, useMemo, useState } from 'react';
import { AppState, Linking, View } from 'react-native';
import { OperationsClient } from '../api/operations';
import { ApiClient, ApiError } from '../api/client';
import { staffCredentials } from '../storage/credentials';
import { useLocale } from '../locales/context';
import { en, mk, type OpsKey } from '../locales/operations';
import {
    Body,
    Button,
    Card,
    ErrorMessage,
    Field,
    Heading,
    Screen,
    styles,
} from '../components/ui';
type Org = { id: string; name: string };
type Session = {
    id: string;
    group: { id: string; name: string };
    trainer: { id: string; display_name: string };
    location: { id: string; name: string };
    local_date: string;
    start_time: string;
    end_time: string;
    phase: string;
    status: string;
    capacity: number;
    reserved_count: number;
    available_spots: number;
    waiting_count: number;
    is_overridden: boolean;
    operational_issues?: string[];
    attendance: Record<string, number>;
    actions: {
        present: boolean;
        absence: boolean;
        correct: boolean;
        walk_in: boolean;
    };
};
type Row = {
    id: string;
    member: { id: string; display_name: string };
    status: string;
    position: number | null;
    member_status?: string;
    walk_in?: boolean;
    finance_access?: boolean;
    membership?: {
        status: string;
        payment_status?: string;
        expiring: boolean;
    } | null;
    attendance: { status: string } | null;
};
type Page = {
    data: Session[];
    today: string;
    timezone: string;
    as_of: string;
    meta: { current_page: number; last_page: number; total: number };
    summary: Record<string, number>;
    financial?: Record<string, number>;
    alerts: { kind: string; session_id: string }[];
    communication: { channel: string; state: string; total: number }[] | null;
};
type Roster = {
    data: Row[];
    session: Session;
    meta: Page['meta'];
    as_of: string;
};
type Profile = {
    display_name: string;
    email?: string | null;
    phone?: string | null;
    status?: string;
    profile_available?: boolean;
    finance_access: boolean;
    memberships?: {
        id: string;
        plan_name: string;
        starts_on: string;
        ends_on: string;
        status: string;
        payment_status?: string;
        balance_minor?: number;
        currency?: string;
        expiring: boolean;
    }[];
};
export function StaffOperations({
    origin,
    exit,
}: {
    origin: string;
    exit: () => void;
}) {
    const { locale, setLocale, t } = useLocale();
    const text = (key: OpsKey) => (locale === 'mk' ? mk : en)[key];
    const stateText = (key: string) =>
        key in en ? text(key as OpsKey) : t('other');
    const client = useMemo(() => new OperationsClient(origin), [origin]);
    const [organizations, setOrganizations] = useState<Org[] | null>(null);
    const [org, setOrg] = useState<Org | null>(null);
    const [permissions, setPermissions] = useState<string[]>([]);
    const [email, setEmail] = useState('');
    const [password, setPassword] = useState('');
    const [error, setError] = useState<unknown>(null);
    const [busy, setBusy] = useState(false);
    const [from, setFrom] = useState('');
    const [until, setUntil] = useState('');
    const [query, setQuery] = useState('');
    const [filtersOpen, setFiltersOpen] = useState(false);
    const [page, setPage] = useState(1);
    const [listPage, setListPage] = useState(1);
    const [session, setSession] = useState('');
    const [dashboard, setDashboard] = useState<Page | null>(null);
    const [roster, setRoster] = useState<Roster | null>(null);
    const [kind, setKind] = useState('reservations');
    const [search, setSearch] = useState('');
    const [term, setTerm] = useState('');
    const [profile, setProfile] = useState<Profile | null>(null);
    const [profileId, setProfileId] = useState('');
    const [matches, setMatches] = useState<Row['member'][]>([]);
    const [correction, setCorrection] = useState<Row | null>(null);
    const [mark, setMark] = useState('present');
    const [revision, setRevision] = useState(0);
    const [notice, setNotice] = useState('');
    const base = org ? `/api/v1/organizations/${org.id}` : '';
    function clearView() {
        client.invalidate();
        setDashboard(null);
        setRoster(null);
        setProfile(null);
        setProfileId('');
        setMatches([]);
        setCorrection(null);
    }
    const account = useCallback(async () => {
        const e = client.generation;
        const r = await client.request<{ data: { organizations: Org[] } }>(
            '/api/v1/staff/account',
        );
        if (e === client.generation) setOrganizations(r.data.organizations);
    }, [client]);
    useEffect(() => {
        client.setLocale(locale);
    }, [client, locale]);
    useEffect(() => {
        let active = true;
        const restoreGeneration = client.generation;
        client.setDenied((kind, token) => {
            if (kind !== 'expired' || token !== client.token) return;
            client.invalidate();
            client.setToken(null);
            setOrganizations(null);
            setOrg(null);
            setPermissions([]);
            setDashboard(null);
            setRoster(null);
            setProfile(null);
            setProfileId('');
            setMatches([]);
            setCorrection(null);
            void staffCredentials.clear().catch(setError);
        });
        void staffCredentials
            .read()
            .then(async (token) => {
                if (
                    !active ||
                    !token ||
                    restoreGeneration !== client.generation
                )
                    return;
                client.setToken(token);
                await account();
            })
            .catch((e) => {
                if (active && restoreGeneration === client.generation)
                    setError(e);
            });
        return () => {
            active = false;
            client.setDenied(() => {});
            client.invalidate();
            client.setToken(null);
        };
    }, [client, account]);
    useEffect(() => {
        const listener = AppState.addEventListener('change', (state) => {
            if (state === 'active') {
                client.invalidate();
                setProfileId('');
                setMatches([]);
                setCorrection(null);
                setRoster(null);
                setDashboard(null);
                setProfile(null);
                setRevision((v) => v + 1);
            }
        });
        return () => listener.remove();
    }, [client]);
    useEffect(() => {
        if (!org) return;
        const c = new AbortController();
        const requestGeneration = client.generation;
        async function load() {
            try {
                const ctx = await client.request<{
                    data: { permissions: string[] };
                }>(`${base}/context`, 'GET', undefined, c.signal);
                if (c.signal.aborted || requestGeneration !== client.generation)
                    return;
                setPermissions(ctx.data.permissions);
                if (session) {
                    const r = await client.request<Roster>(
                        `${base}/operations/sessions/${session}/roster`,
                        'POST',
                        { page, kind, search: term },
                        c.signal,
                    );
                    if (
                        !c.signal.aborted &&
                        requestGeneration === client.generation
                    )
                        setRoster(r);
                } else {
                    const d = await client.request<Page>(
                        `${base}/operations?${query}${query ? '&' : ''}page=${page}`,
                        'GET',
                        undefined,
                        c.signal,
                    );
                    if (
                        !c.signal.aborted &&
                        requestGeneration === client.generation
                    ) {
                        setDashboard(d);
                        if (!query) {
                            setFrom(d.today);
                            setUntil(d.today);
                        }
                    }
                }
            } catch (e) {
                if (
                    !c.signal.aborted &&
                    requestGeneration === client.generation
                ) {
                    setDashboard(null);
                    setRoster(null);
                    setProfile(null);
                    setError(e);
                    if (
                        e instanceof ApiError &&
                        ['expired', 'forbidden', 'missing'].includes(e.kind)
                    ) {
                        setPermissions([]);
                    }
                }
            }
        }
        void load();
        return () => c.abort();
    }, [base, org, session, query, page, kind, term, revision, client]);
    async function action(work: () => Promise<void>) {
        if (!client.begin()) return;
        setBusy(true);
        setError(null);
        try {
            await work();
        } catch (e) {
            setError(e);
        } finally {
            client.end();
            setBusy(false);
        }
    }
    function refresh() {
        clearView();
        setRevision((v) => v + 1);
    }
    async function write(path: string, data: unknown) {
        const e = client.generation;
        try {
            await client.request(
                `${base}/sessions/${session}/attendance${path}`,
                'POST',
                data,
            );
            if (e === client.generation) setNotice(t('saved'));
        } catch (err) {
            if (err instanceof ApiError && err.kind === 'conflict')
                setNotice(text('genericConflict'));
            throw err;
        } finally {
            if (e === client.generation) refresh();
        }
    }
    async function login() {
        clearView();
        const loginGeneration = client.generation;
        client.setToken(null);
        await staffCredentials.clear();
        const r = await client.request<{ data: { token: string } }>(
            '/api/v1/staff-auth/token',
            'POST',
            { email, password, device_name: 'GT staff native' },
        );
        if (loginGeneration !== client.generation) return;
        client.setToken(r.data.token);
        setPassword('');
        await staffCredentials.write(r.data.token);
        if (loginGeneration === client.generation) await account();
    }
    async function logout() {
        clearView();
        const token = client.token;
        client.setToken(null);
        setOrg(null);
        setOrganizations(null);
        setPermissions([]);
        await staffCredentials.clear();
        if (token) {
            const revoke = new ApiClient(origin);
            revoke.token = token;
            await revoke.request('/api/v1/member/token/logout', 'POST', {});
        }
    }
    function open(id: string) {
        clearView();
        setSession(id);
        if (id) {
            setListPage(page);
            setPage(1);
        } else setPage(listPage);
        setTerm('');
        setSearch('');
        setKind('reservations');
        setRevision((v) => v + 1);
    }
    function applyFilter(field: string, value: string) {
        const next = new URLSearchParams(query);
        if (value) next.set(field, value);
        else next.delete(field);
        clearView();
        setPage(1);
        setQuery(next.toString());
        setRevision((v) => v + 1);
    }
    async function viewProfile(id: string) {
        setProfile(null);
        setProfileId(id);
        const e = client.generation;
        const r = await client.request<{ data: Profile }>(
            `${base}/operations/sessions/${session}/members/${id}`,
        );
        if (e === client.generation) setProfile(r.data);
    }
    return (
        <Screen
            headerless
            refresh={org ? refresh : undefined}
            refreshing={false}
        >
            <Heading>{text('operations')}</Heading>
            <View style={styles.row}>
                <Button label="МК" onPress={() => setLocale('mk')} />
                <Button label="EN" onPress={() => setLocale('en')} />
            </View>
            <ErrorMessage error={error} />
            {!!notice && <Body>{notice}</Body>}
            {!organizations ? (
                <>
                    <Field
                        label={t('email')}
                        value={email}
                        onChange={setEmail}
                        email
                    />
                    <Field
                        label={t('password')}
                        value={password}
                        onChange={setPassword}
                        secret
                    />
                    <Button
                        label={t('login')}
                        disabled={busy}
                        onPress={() => void action(login)}
                    />
                    <Button
                        label={text('memberMode')}
                        disabled={busy}
                        onPress={() => {
                            clearView();
                            exit();
                        }}
                    />
                </>
            ) : !org ? (
                <>
                    <Body>{text('selectStudio')}</Body>
                    {organizations.length === 0 && <Body>{text('empty')}</Body>}
                    {organizations.map((o) => (
                        <Button
                            key={o.id}
                            label={o.name}
                            onPress={() => {
                                clearView();
                                setOrg(o);
                                setSession('');
                                setPage(1);
                                setQuery('');
                            }}
                        />
                    ))}
                    <Button
                        label={text('logout')}
                        disabled={busy}
                        onPress={() => void action(logout)}
                    />
                </>
            ) : (
                <>
                    <Heading>{org.name}</Heading>
                    <Button
                        label={text('selectStudio')}
                        disabled={busy}
                        onPress={() => {
                            clearView();
                            setOrg(null);
                        }}
                    />
                    <Button
                        label={text('logout')}
                        disabled={busy}
                        onPress={() => void action(logout)}
                    />
                    <Body>{text('stale')}</Body>
                    <Button
                        label={text('refresh')}
                        disabled={busy}
                        onPress={refresh}
                    />
                    {!session && !dashboard && !error && (
                        <Body>{t('loading')}</Body>
                    )}
                    {!!session && !roster && !error && (
                        <Body>{t('loading')}</Body>
                    )}
                    {!session ? (
                        <>
                            <Field
                                label={text('date')}
                                value={from}
                                onChange={setFrom}
                            />
                            <Field
                                label={text('until')}
                                value={until}
                                onChange={setUntil}
                            />
                            <Button
                                label={text('refresh')}
                                onPress={() => {
                                    clearView();
                                    setPage(1);
                                    setQuery(
                                        `from=${encodeURIComponent(from)}&until=${encodeURIComponent(until)}`,
                                    );
                                    setRevision((v) => v + 1);
                                }}
                            />
                            {dashboard && (
                                <>
                                    <Body>
                                        {dashboard.timezone} · {text('updated')}{' '}
                                        {dashboard.as_of}
                                    </Body>
                                    <Body>
                                        {text('total')}: {dashboard.meta.total}{' '}
                                        · {text('reserved')}:{' '}
                                        {dashboard.summary.reserved} ·{' '}
                                        {text('present')}:{' '}
                                        {dashboard.summary.present}
                                    </Body>
                                    {!dashboard.data.length && (
                                        <Body>{text('empty')}</Body>
                                    )}
                                    <Button
                                        label={text(
                                            filtersOpen
                                                ? 'hideFilters'
                                                : 'showFilters',
                                        )}
                                        onPress={() =>
                                            setFiltersOpen((v) => !v)
                                        }
                                    />
                                    {filtersOpen && (
                                        <>
                                            <Body>{text('status')}</Body>
                                            <View style={styles.row}>
                                                {(
                                                    [
                                                        'all',
                                                        'scheduled',
                                                        'cancelled',
                                                    ] as const
                                                ).map((value) => (
                                                    <Button
                                                        key={value}
                                                        label={text(value)}
                                                        onPress={() =>
                                                            applyFilter(
                                                                'status',
                                                                value,
                                                            )
                                                        }
                                                    />
                                                ))}
                                            </View>
                                            {(
                                                [
                                                    'trainer',
                                                    'location',
                                                    'group',
                                                ] as const
                                            ).map((field) => (
                                                <Card key={field}>
                                                    <Body>
                                                        {text(
                                                            field === 'trainer'
                                                                ? 'filterTrainer'
                                                                : field ===
                                                                    'location'
                                                                  ? 'filterLocation'
                                                                  : 'filterGroup',
                                                        )}
                                                    </Body>
                                                    <Button
                                                        label={text('all')}
                                                        onPress={() =>
                                                            applyFilter(
                                                                field + '_id',
                                                                '',
                                                            )
                                                        }
                                                    />
                                                    {Array.from(
                                                        new Map(
                                                            dashboard.data.map(
                                                                (s) => [
                                                                    s[field].id,
                                                                    field ===
                                                                    'trainer'
                                                                        ? s
                                                                              .trainer
                                                                              .display_name
                                                                        : field ===
                                                                            'location'
                                                                          ? s
                                                                                .location
                                                                                .name
                                                                          : s
                                                                                .group
                                                                                .name,
                                                                ],
                                                            ),
                                                        ).entries(),
                                                    ).map(([id, name]) => (
                                                        <Button
                                                            key={id}
                                                            label={name}
                                                            onPress={() =>
                                                                applyFilter(
                                                                    field +
                                                                        '_id',
                                                                    id,
                                                                )
                                                            }
                                                        />
                                                    ))}
                                                </Card>
                                            ))}
                                        </>
                                    )}
                                    {dashboard.data.map((s) => (
                                        <Card key={s.id}>
                                            <Heading>{s.group.name}</Heading>
                                            <Body>
                                                {s.local_date} · {s.start_time}–
                                                {s.end_time}
                                            </Body>
                                            <Body>
                                                {s.trainer.display_name} ·{' '}
                                                {s.location.name} ·{' '}
                                                {stateText(s.phase)}
                                            </Body>
                                            <Body>
                                                {text('reserved')}:{' '}
                                                {s.reserved_count}/{s.capacity}{' '}
                                                · {text('free')}:{' '}
                                                {s.available_spots} ·{' '}
                                                {text('waiting')}:{' '}
                                                {s.waiting_count}
                                            </Body>
                                            {s.is_overridden && (
                                                <Body>{text('changed')}</Body>
                                            )}
                                            <Button
                                                label={text('roster')}
                                                onPress={() => open(s.id)}
                                            />
                                        </Card>
                                    ))}
                                    <View style={styles.row}>
                                        <Button
                                            label={text('previous')}
                                            disabled={page === 1}
                                            onPress={() => {
                                                clearView();
                                                setPage(page - 1);
                                            }}
                                        />
                                        <Button
                                            label={text('next')}
                                            disabled={
                                                page >= dashboard.meta.last_page
                                            }
                                            onPress={() => {
                                                clearView();
                                                setPage(page + 1);
                                            }}
                                        />
                                    </View>
                                    <Heading>{text('alerts')}</Heading>
                                    {Object.entries(
                                        dashboard.financial ?? {},
                                    ).map(
                                        ([key, count]) =>
                                            count > 0 && (
                                                <Body key={key}>
                                                    {stateText(key)}: {count}
                                                </Body>
                                            ),
                                    )}
                                    {dashboard.alerts.map((a) => (
                                        <Button
                                            key={`${a.kind}:${a.session_id}`}
                                            label={stateText(a.kind)}
                                            onPress={() => open(a.session_id)}
                                        />
                                    ))}
                                    {dashboard.communication !== null && (
                                        <>
                                            <Heading>
                                                {text('communication')}
                                            </Heading>
                                            <Body>{text('caveat')}</Body>
                                            <Button
                                                label={text('webCommunication')}
                                                onPress={() =>
                                                    void Linking.openURL(
                                                        `${origin}/settings?organization=${org.id}`,
                                                    ).catch(setError)
                                                }
                                            />
                                            {dashboard.communication.map(
                                                (d) => (
                                                    <Body
                                                        key={`${d.channel}:${d.state}`}
                                                    >
                                                        {stateText(d.channel)} ·{' '}
                                                        {stateText(d.state)}:{' '}
                                                        {d.total}
                                                    </Body>
                                                ),
                                            )}
                                        </>
                                    )}
                                </>
                            )}
                        </>
                    ) : (
                        <>
                            <Button
                                label={text('back')}
                                disabled={busy}
                                onPress={() => open('')}
                            />
                            <Field
                                label={text('search')}
                                value={search}
                                onChange={setSearch}
                            />
                            <Button
                                label={text('search')}
                                disabled={busy}
                                onPress={() => {
                                    clearView();
                                    setPage(1);
                                    setTerm(search);
                                }}
                            />
                            <View style={styles.row}>
                                <Button
                                    label={text('reservations')}
                                    onPress={() => {
                                        clearView();
                                        setKind('reservations');
                                        setPage(1);
                                    }}
                                />
                                {permissions.includes('waiting_list.view') && (
                                    <Button
                                        label={text('waiting')}
                                        onPress={() => {
                                            clearView();
                                            setKind('waiting');
                                            setPage(1);
                                        }}
                                    />
                                )}
                            </View>
                            {roster && (
                                <>
                                    <Heading>
                                        {roster.session.group.name}
                                    </Heading>
                                    <Body>
                                        {text('reserved')}:{' '}
                                        {roster.session.reserved_count}/
                                        {roster.session.capacity} ·{' '}
                                        {text('free')}:{' '}
                                        {roster.session.available_spots} ·{' '}
                                        {text('waiting')}:{' '}
                                        {roster.session.waiting_count}
                                    </Body>
                                    <Body>
                                        {text('total')}: {roster.meta.total}
                                    </Body>
                                    {!roster.data.length && (
                                        <Body>{text('empty')}</Body>
                                    )}
                                    {roster.data.map((row) => (
                                        <Card key={row.id}>
                                            <Heading>
                                                {row.member.display_name}
                                            </Heading>
                                            <Body>
                                                {row.position !== null
                                                    ? `#${row.position} · `
                                                    : ''}
                                                {stateText(row.status)} ·{' '}
                                                {stateText(
                                                    row.attendance?.status ??
                                                        'pending',
                                                )}
                                            </Body>
                                            {row.member_status && (
                                                <Body>
                                                    {stateText(
                                                        row.member_status,
                                                    )}
                                                </Body>
                                            )}
                                            {row.walk_in && (
                                                <Body>{text('walk')}</Body>
                                            )}
                                            {row.finance_access && (
                                                <Body>
                                                    {row.membership
                                                        ? stateText(
                                                              row.membership
                                                                  .status,
                                                          )
                                                        : text('noMembership')}
                                                    {row.membership?.expiring &&
                                                        ` · ${text('expiring')}`}
                                                    {row.membership
                                                        ?.payment_status &&
                                                        ` · ${stateText(row.membership.payment_status)}`}
                                                </Body>
                                            )}
                                            <Button
                                                label={text('profile')}
                                                disabled={busy}
                                                onPress={() =>
                                                    void action(() =>
                                                        viewProfile(
                                                            row.member.id,
                                                        ),
                                                    )
                                                }
                                            />
                                            {row.status === 'active' &&
                                                (row.member_status ??
                                                    'active') === 'active' &&
                                                kind === 'reservations' && (
                                                    <>
                                                        {!row.attendance &&
                                                            roster.session
                                                                .actions
                                                                .present && (
                                                                <Button
                                                                    label={text(
                                                                        'present',
                                                                    )}
                                                                    disabled={
                                                                        busy
                                                                    }
                                                                    onPress={() =>
                                                                        void action(
                                                                            () =>
                                                                                write(
                                                                                    '',
                                                                                    {
                                                                                        member_id:
                                                                                            row
                                                                                                .member
                                                                                                .id,
                                                                                        status: 'present',
                                                                                    },
                                                                                ),
                                                                        )
                                                                    }
                                                                />
                                                            )}
                                                        {!row.attendance &&
                                                            roster.session
                                                                .actions
                                                                .absence &&
                                                            (
                                                                [
                                                                    'absent',
                                                                    'no_show',
                                                                ] as const
                                                            ).map((status) => (
                                                                <Button
                                                                    key={status}
                                                                    label={text(
                                                                        status,
                                                                    )}
                                                                    disabled={
                                                                        busy
                                                                    }
                                                                    onPress={() =>
                                                                        void action(
                                                                            () =>
                                                                                write(
                                                                                    '',
                                                                                    {
                                                                                        member_id:
                                                                                            row
                                                                                                .member
                                                                                                .id,
                                                                                        status,
                                                                                    },
                                                                                ),
                                                                        )
                                                                    }
                                                                />
                                                            ))}
                                                        {roster.session.actions
                                                            .correct && (
                                                            <Button
                                                                label={text(
                                                                    'correct',
                                                                )}
                                                                disabled={busy}
                                                                onPress={() => {
                                                                    setCorrection(
                                                                        row,
                                                                    );
                                                                    setMark(
                                                                        'present',
                                                                    );
                                                                }}
                                                            />
                                                        )}
                                                    </>
                                                )}
                                        </Card>
                                    ))}
                                    <View style={styles.row}>
                                        <Button
                                            label={text('previous')}
                                            disabled={page === 1 || busy}
                                            onPress={() => {
                                                clearView();
                                                setPage(page - 1);
                                            }}
                                        />
                                        <Button
                                            label={text('next')}
                                            disabled={
                                                page >= roster.meta.last_page ||
                                                busy
                                            }
                                            onPress={() => {
                                                clearView();
                                                setPage(page + 1);
                                            }}
                                        />
                                    </View>
                                    {correction && (
                                        <Card>
                                            <Heading>
                                                {text('confirm')} ·{' '}
                                                {correction.member.display_name}
                                            </Heading>
                                            {[
                                                'present',
                                                ...(roster.session.phase ===
                                                'completed'
                                                    ? ['absent', 'no_show']
                                                    : []),
                                            ].map((v) => (
                                                <Button
                                                    key={v}
                                                    label={stateText(v)}
                                                    disabled={
                                                        mark === v || busy
                                                    }
                                                    onPress={() => setMark(v)}
                                                />
                                            ))}
                                            <Body>{stateText(mark)}</Body>
                                            {(
                                                [
                                                    'mistake',
                                                    'late_confirmation',
                                                ] as const
                                            ).map((reason) => (
                                                <Button
                                                    key={reason}
                                                    label={
                                                        reason === 'mistake'
                                                            ? text('mistake')
                                                            : text('late')
                                                    }
                                                    disabled={busy}
                                                    onPress={() =>
                                                        void action(() =>
                                                            write('/correct', {
                                                                member_id:
                                                                    correction
                                                                        .member
                                                                        .id,
                                                                status: mark,
                                                                reason,
                                                            }),
                                                        )
                                                    }
                                                />
                                            ))}
                                            <Button
                                                label={text('cancel')}
                                                disabled={busy}
                                                onPress={() =>
                                                    setCorrection(null)
                                                }
                                            />
                                        </Card>
                                    )}
                                    {roster.session.actions.walk_in && (
                                        <>
                                            <Button
                                                label={text('lookup')}
                                                disabled={
                                                    busy ||
                                                    search.trim().length < 2
                                                }
                                                onPress={() =>
                                                    void action(async () => {
                                                        const e =
                                                            client.generation;
                                                        const r =
                                                            await client.request<{
                                                                data: Row['member'][];
                                                            }>(
                                                                `${base}/sessions/${session}/attendance/member-lookup`,
                                                                'POST',
                                                                { search },
                                                            );
                                                        if (
                                                            e ===
                                                            client.generation
                                                        )
                                                            setMatches(r.data);
                                                    })
                                                }
                                            />
                                            {matches.map((m) => (
                                                <Card key={m.id}>
                                                    <Body>
                                                        {m.display_name}
                                                    </Body>
                                                    <Button
                                                        label={text('walk')}
                                                        disabled={busy}
                                                        onPress={() =>
                                                            void action(() =>
                                                                write(
                                                                    '/walk-in',
                                                                    {
                                                                        member_id:
                                                                            m.id,
                                                                    },
                                                                ),
                                                            )
                                                        }
                                                    />
                                                </Card>
                                            ))}
                                        </>
                                    )}
                                </>
                            )}
                            {!!profileId && (
                                <Card>
                                    <Heading>{text('profile')}</Heading>
                                    {!profile && !error && (
                                        <Body>{t('loading')}</Body>
                                    )}
                                    {profile && (
                                        <>
                                            <Body>{profile.display_name}</Body>
                                            {profile.status && (
                                                <Body>
                                                    {stateText(profile.status)}
                                                </Body>
                                            )}
                                            {profile.email && (
                                                <Body>{profile.email}</Body>
                                            )}
                                            {profile.phone && (
                                                <Body>{profile.phone}</Body>
                                            )}
                                            {profile.profile_available && (
                                                <Button
                                                    label={text('fullProfile')}
                                                    onPress={() =>
                                                        void Linking.openURL(
                                                            `${origin}/members/${profileId}?organization=${org.id}`,
                                                        ).catch(setError)
                                                    }
                                                />
                                            )}

                                            <Body>{text('noUses')}</Body>
                                            {!profile.finance_access ? (
                                                <Body>
                                                    {text('restricted')}
                                                </Body>
                                            ) : !profile.memberships?.length ? (
                                                <Body>
                                                    {text('noMembership')}
                                                </Body>
                                            ) : (
                                                profile.memberships.map((m) => (
                                                    <Card key={m.id}>
                                                        <Body>
                                                            {m.plan_name} ·{' '}
                                                            {stateText(
                                                                m.status,
                                                            )}
                                                        </Body>
                                                        <Body>
                                                            {m.starts_on} –{' '}
                                                            {m.ends_on}
                                                        </Body>
                                                        {m.expiring && (
                                                            <Body>
                                                                {text(
                                                                    'expiring',
                                                                )}
                                                            </Body>
                                                        )}
                                                        {m.payment_status && (
                                                            <Body>
                                                                {stateText(
                                                                    m.payment_status,
                                                                )}{' '}
                                                                ·{' '}
                                                                {m.balance_minor ===
                                                                undefined
                                                                    ? ''
                                                                    : `${(m.balance_minor / 100).toFixed(2)} ${m.currency}`}
                                                            </Body>
                                                        )}
                                                    </Card>
                                                ))
                                            )}
                                        </>
                                    )}
                                    {profile?.finance_access && (
                                        <>
                                            <Body>
                                                {text('webContext')} {org.name}
                                            </Body>
                                            <Button
                                                label={text('webMemberships')}
                                                onPress={() =>
                                                    void Linking.openURL(
                                                        `${origin}/memberships?organization=${org.id}`,
                                                    ).catch(setError)
                                                }
                                            />
                                            {permissions.includes(
                                                'payments.view',
                                            ) && (
                                                <Button
                                                    label={text('webPayments')}
                                                    onPress={() =>
                                                        void Linking.openURL(
                                                            `${origin}/payments?organization=${org.id}`,
                                                        ).catch(setError)
                                                    }
                                                />
                                            )}
                                        </>
                                    )}
                                    <Button
                                        label={text('back')}
                                        onPress={() => {
                                            setProfile(null);
                                            setProfileId('');
                                        }}
                                    />
                                </Card>
                            )}
                        </>
                    )}
                </>
            )}
        </Screen>
    );
}
