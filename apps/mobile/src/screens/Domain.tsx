import { useState, type ReactNode } from 'react';
import { Alert, View } from 'react-native';
import {
    useNavigation,
    useRoute,
    type RouteProp,
} from '@react-navigation/native';
import type { NativeStackNavigationProp } from '@react-navigation/native-stack';
import type { UseQueryResult } from '@tanstack/react-query';
import { useMember } from '../auth/context';
import { useAction, useMemberQuery } from '../hooks/member';
import { statusKey, useLocale } from '../locales/context';
import type { TextKey } from '../locales/strings';
import {
    Body,
    Button,
    Card,
    ErrorMessage,
    Heading,
    Loading,
    Pager,
    Screen,
    styles,
} from '../components/ui';
import type { Routes } from '../navigation/types';
import type {
    Attendance,
    Group,
    Membership,
    Notice,
    Page,
    Payment,
    Session,
    Waiting,
} from '../api/types';
import { dateTime, money, wallTime } from '../utils/format';
const useNav = () => useNavigation<NativeStackNavigationProp<Routes>>();
export function QueryScreen<T>({
    title,
    query,
    children,
}: {
    title: TextKey;
    query: UseQueryResult<T>;
    children: (data: T) => ReactNode;
}) {
    const { t } = useLocale();
    const { controller } = useMember();
    return (
        <Screen
            refresh={() => void query.refetch()}
            refreshing={query.isFetching}
        >
            <Heading>{t(title)}</Heading>
            <ErrorMessage error={query.error} />
            {query.isFetching ? (
                <Loading />
            ) : query.isError ? (
                <Button
                    label={t('retry')}
                    onPress={() => void query.refetch()}
                />
            ) : query.data ? (
                children(query.data)
            ) : null}
            {query.isError && (
                <Button
                    label={t('switchStudio')}
                    onPress={() => controller.leaveOrganization()}
                />
            )}
        </Screen>
    );
}
function Listing<T>({
    title,
    query,
    page,
    setPage,
    render,
}: {
    title: TextKey;
    query: UseQueryResult<Page<T>>;
    page: number;
    setPage: (page: number) => void;
    render: (item: T, index: number) => ReactNode;
}) {
    const { t } = useLocale();
    return (
        <QueryScreen title={title} query={query}>
            {(data) => (
                <>
                    {data.data.length === 0 && <Body>{t('empty')}</Body>}
                    {data.data.map(render)}
                    <Pager
                        page={page}
                        last={data.meta.last_page}
                        setPage={setPage}
                    />
                </>
            )}
        </QueryScreen>
    );
}
export function SessionCard({ session }: { session: Session }) {
    const nav = useNav();
    const { t, locale } = useLocale();
    return (
        <Card>
            <Body>{session.group.name}</Body>
            <Body>
                {dateTime(session.starts_at, session.timezone, locale)} –{' '}
                {dateTime(session.ends_at, session.timezone, locale)}
            </Body>
            <Body>
                {t('timezone')}: {session.timezone}
            </Body>
            <Body>{session.location.name}</Body>
            <Body>
                {t('available')}: {session.available_spots}
            </Body>
            <Body>{t(statusKey(session.status))}</Body>
            {session.reservation && (
                <Body>
                    {t('bookingState')}:{' '}
                    {t(statusKey(session.reservation.status))}
                </Body>
            )}
            {session.waiting_entry && (
                <Body>
                    {t('waitingState')}:{' '}
                    {t(statusKey(session.waiting_entry.status))}
                </Body>
            )}
            <Button
                label={`${t('details')}: ${session.group.name}`}
                onPress={() => nav.navigate('Session', { id: session.id })}
            />
        </Card>
    );
}
export function Sessions() {
    const { api, organization } = useMember();
    const route = useRoute<RouteProp<Routes, 'Sessions'>>();
    const [page, setPage] = useState(1);
    const query = useMemberQuery(
        [
            'sessions',
            page,
            route.params?.relationship ?? 'all',
            route.params?.group ?? '',
        ],
        (signal) =>
            api.sessions(
                organization.id,
                page,
                route.params?.relationship ?? 'all',
                route.params?.group,
                signal,
            ),
    );
    return (
        <Listing
            title="sessions"
            query={query}
            page={page}
            setPage={setPage}
            render={(s) => <SessionCard key={s.id} session={s} />}
        />
    );
}
export function Groups() {
    const { api, organization } = useMember();
    const { t } = useLocale();
    const nav = useNav();
    const [page, setPage] = useState(1);
    const query = useMemberQuery(['groups', page], (signal) =>
        api.groups(organization.id, page, signal),
    );
    return (
        <Listing
            title="groups"
            query={query}
            page={page}
            setPage={setPage}
            render={(g: Group) => (
                <Card key={g.id}>
                    <Body>{g.name}</Body>
                    <Body>{g.location.name}</Body>
                    <Button
                        label={`${t('details')}: ${g.name}`}
                        onPress={() => nav.navigate('Group', { id: g.id })}
                    />
                </Card>
            )}
        />
    );
}
export function GroupDetail() {
    const { api, organization } = useMember();
    const { t } = useLocale();
    const nav = useNav();
    const { params } = useRoute<RouteProp<Routes, 'Group'>>();
    const query = useMemberQuery(['group', params.id], (signal) =>
        api.group(organization.id, params.id, signal),
    );
    const weekdays: TextKey[] = [
        'sunday',
        'monday',
        'tuesday',
        'wednesday',
        'thursday',
        'friday',
        'saturday',
    ];
    return (
        <QueryScreen title="groups" query={query}>
            {({ data: g }) => (
                <>
                    <Card>
                        <Heading>{g.name}</Heading>
                        {g.description && <Body>{g.description}</Body>}
                        <Body>
                            {t('location')}: {g.location.name}
                        </Body>
                        {g.trainer && (
                            <Body>
                                {t('trainer')}: {g.trainer.name}
                            </Body>
                        )}
                        <Body>
                            {t('timezone')}: {g.timezone}
                        </Body>
                    </Card>
                    <Heading>{t('schedule')}</Heading>
                    {g.schedule.map((s, i) => (
                        <Card key={i}>
                            <Body>
                                {t(weekdays[s.weekday % 7])}{' '}
                                {wallTime(s.start_minute)}–
                                {wallTime(s.end_minute)}
                            </Body>
                            <Body>
                                {s.effective_from} – {s.effective_until ?? '…'}
                            </Body>
                        </Card>
                    ))}
                    <Button
                        label={t('sessions')}
                        onPress={() =>
                            nav.navigate('Sessions', { group: g.id })
                        }
                    />
                </>
            )}
        </QueryScreen>
    );
}
export function ActionFeedback({
    action,
}: {
    action: ReturnType<typeof useAction>;
}) {
    const { t } = useLocale();
    return (
        <>
            <ErrorMessage error={action.error} />
            {action.ambiguous && <Body>{t('checkState')}</Body>}
            {action.done && <Body>{t('mutationDone')}</Body>}
        </>
    );
}
export function SessionDetail() {
    const { api, organization } = useMember();
    const { t, locale } = useLocale();
    const { params } = useRoute<RouteProp<Routes, 'Session'>>();
    const action = useAction();
    const query = useMemberQuery(['session', params.id], (signal) =>
        api.session(organization.id, params.id, signal),
    );
    function cancel(kind: 'reservations' | 'waiting-list', id: string) {
        Alert.alert(t('confirm'), t('cancelQuestion'), [
            { text: t('cancelAction'), style: 'cancel' },
            {
                text: t('confirm'),
                style: 'destructive',
                onPress: () =>
                    void action.run(() =>
                        api.cancel(organization.id, kind, id),
                    ),
            },
        ]);
    }
    return (
        <QueryScreen title="sessions" query={query}>
            {({ data: s }) => (
                <>
                    <Card>
                        <Heading>{s.group.name}</Heading>
                        <Body>
                            {dateTime(s.starts_at, s.timezone, locale)} –{' '}
                            {dateTime(s.ends_at, s.timezone, locale)}
                        </Body>
                        <Body>
                            {t('timezone')}: {s.timezone}
                        </Body>
                        <Body>
                            {t('location')}: {s.location.name}
                        </Body>
                        {s.trainer && (
                            <Body>
                                {t('trainer')}: {s.trainer.name}
                            </Body>
                        )}
                        <Body>
                            {t('status')}: {t(statusKey(s.status))}
                        </Body>
                        <Body>
                            {t('available')}: {s.available_spots} / {s.capacity}
                        </Body>
                        <Body>
                            {t('attendanceState')}:{' '}
                            {t(statusKey(s.attendance_status))}
                        </Body>
                        {s.reservation && (
                            <Body>
                                {t('bookingState')}:{' '}
                                {t(statusKey(s.reservation.status))}
                            </Body>
                        )}
                        {s.waiting_entry && (
                            <Body>
                                {t('waitingState')}:{' '}
                                {t(statusKey(s.waiting_entry.status))}
                            </Body>
                        )}
                    </Card>
                    <ActionFeedback action={action} />
                    {s.can_reserve && (
                        <Button
                            label={t('reserve')}
                            disabled={action.busy}
                            onPress={() =>
                                void action.run(() =>
                                    api.reserve(organization.id, s.id, false),
                                )
                            }
                        />
                    )}{' '}
                    {s.can_join_waiting && (
                        <Button
                            label={t('wait')}
                            disabled={action.busy}
                            onPress={() =>
                                void action.run(() =>
                                    api.reserve(organization.id, s.id, true),
                                )
                            }
                        />
                    )}{' '}
                    {s.reservation?.can_cancel && (
                        <Button
                            label={t('cancelReservation')}
                            disabled={action.busy}
                            onPress={() =>
                                cancel('reservations', s.reservation!.id)
                            }
                        />
                    )}{' '}
                    {s.waiting_entry?.can_cancel && (
                        <Button
                            label={t('cancelWaiting')}
                            disabled={action.busy}
                            onPress={() =>
                                cancel('waiting-list', s.waiting_entry!.id)
                            }
                        />
                    )}
                </>
            )}
        </QueryScreen>
    );
}
export function Activity() {
    const nav = useNav();
    const { t } = useLocale();
    return (
        <Screen>
            <Heading>{t('activity')}</Heading>
            <Button
                label={t('upcoming')}
                onPress={() =>
                    nav.navigate('Sessions', { relationship: 'reserved' })
                }
            />
            {(
                [
                    ['CheckIn', 'checkIn'],
                    ['Waiting', 'waiting'],
                    ['Attendance', 'attendance'],
                    ['Memberships', 'memberships'],
                    ['Payments', 'payments'],
                ] as const
            ).map(([screen, label]) => (
                <Button
                    key={screen}
                    label={t(label)}
                    onPress={() => nav.navigate(screen)}
                />
            ))}
        </Screen>
    );
}
export function WaitingList() {
    const { api, organization } = useMember();
    const { t, locale } = useLocale();
    const nav = useNav();
    const [page, setPage] = useState(1);
    const query = useMemberQuery(['waiting', page], (signal) =>
        api.waiting(organization.id, page, signal),
    );
    return (
        <Listing
            title="waiting"
            query={query}
            page={page}
            setPage={setPage}
            render={(w: Waiting) => (
                <Card key={w.id}>
                    <Body>{w.session_name}</Body>
                    <Body>
                        {dateTime(w.starts_at, w.timezone, locale)} (
                        {w.timezone})
                    </Body>
                    <Body>{t(statusKey(w.status))}</Body>
                    <Button
                        label={t('details')}
                        onPress={() =>
                            nav.navigate('Session', { id: w.session_id })
                        }
                    />
                </Card>
            )}
        />
    );
}
export function AttendanceHistory() {
    const { api, organization } = useMember();
    const { t, locale } = useLocale();
    const [page, setPage] = useState(1);
    const query = useMemberQuery(['attendance', page], (signal) =>
        api.attendance(organization.id, page, signal),
    );
    return (
        <Listing
            title="attendance"
            query={query}
            page={page}
            setPage={setPage}
            render={(a: Attendance) => (
                <Card key={a.id}>
                    <Body>{a.session.name}</Body>
                    <Body>
                        {dateTime(
                            a.session.starts_at,
                            a.session.timezone,
                            locale,
                        )}{' '}
                        ({a.session.timezone})
                    </Body>
                    <Body>{t(statusKey(a.status))}</Body>
                    {a.method === 'qr' && <Body>{t('qrMethod')}</Body>}
                </Card>
            )}
        />
    );
}
export function MembershipCard({ membership: m }: { membership: Membership }) {
    const { t, locale } = useLocale();
    return (
        <Card>
            <Body>{m.plan_name}</Body>
            <Body>{t(statusKey(m.status))}</Body>
            <Body>
                {m.starts_on} – {m.ends_on}
            </Body>
            <Body>
                {t('price')}: {money(m.agreed_price_minor, m.currency, locale)}
            </Body>
            <Body>
                {t('paid')}: {money(m.net_paid_minor, m.currency, locale)}
            </Body>
            <Body>
                {t('balance')}: {money(m.balance_minor, m.currency, locale)}
            </Body>
        </Card>
    );
}
export function Memberships() {
    const { api, organization } = useMember();
    const [page, setPage] = useState(1);
    const query = useMemberQuery(['memberships', page], (signal) =>
        api.memberships(organization.id, page, signal),
    );
    return (
        <Listing
            title="memberships"
            query={query}
            page={page}
            setPage={setPage}
            render={(m) => <MembershipCard key={m.id} membership={m} />}
        />
    );
}
export function Payments() {
    const { api, organization } = useMember();
    const { t, locale } = useLocale();
    const [page, setPage] = useState(1);
    const query = useMemberQuery(['payments', page], (signal) =>
        api.payments(organization.id, page, signal),
    );
    return (
        <Listing
            title="payments"
            query={query}
            page={page}
            setPage={setPage}
            render={(p: Payment) => (
                <Card key={p.id}>
                    <Body>{money(p.amount_minor, p.currency, locale)}</Body>
                    <Body>
                        {dateTime(p.paid_at, organization.timezone, locale)} (
                        {organization.timezone})
                    </Body>
                    <Body>{t(statusKey(p.status))}</Body>
                    {p.reversal && (
                        <Body>
                            {t('reversal')}:{' '}
                            {money(p.reversal.amount_minor, p.currency, locale)}{' '}
                            ·{' '}
                            {dateTime(
                                p.reversal.created_at,
                                organization.timezone,
                                locale,
                            )}
                        </Body>
                    )}
                </Card>
            )}
        />
    );
}
export function Inbox() {
    const { api, organization } = useMember();
    const { t } = useLocale();
    const nav = useNav();
    const [page, setPage] = useState(1);
    const action = useAction();
    const query = useMemberQuery(['notifications', page], (signal) =>
        api.notices(organization.id, page, signal),
    );
    return (
        <QueryScreen title="notifications" query={query}>
            {(data) => (
                <>
                    <Body>
                        {t('unread')}: {data.unread_count ?? 0}
                    </Body>
                    <ActionFeedback action={action} />
                    {data.data.some((n) => !n.read_at) && (
                        <Button
                            label={t('readPage')}
                            disabled={action.busy}
                            onPress={() =>
                                void action.run(() =>
                                    api.read(
                                        organization.id,
                                        data.data
                                            .filter((n) => !n.read_at)
                                            .map((n) => n.id),
                                    ),
                                )
                            }
                        />
                    )}{' '}
                    {data.data.length === 0 && <Body>{t('empty')}</Body>}
                    {data.data.map((n: Notice) => (
                        <Card key={n.id}>
                            <Body>{n.title}</Body>
                            <Body>
                                {t(n.read_at ? 'readStatus' : 'unread')}
                            </Body>
                            <Button
                                label={`${t('details')}: ${n.title}`}
                                onPress={() =>
                                    nav.navigate('Notice', { id: n.id })
                                }
                            />
                        </Card>
                    ))}
                    <Pager
                        page={page}
                        last={data.meta.last_page}
                        setPage={setPage}
                    />
                </>
            )}
        </QueryScreen>
    );
}
export function NoticeDetail() {
    const { api, organization } = useMember();
    const { t } = useLocale();
    const { params } = useRoute<RouteProp<Routes, 'Notice'>>();
    const action = useAction();
    const query = useMemberQuery(['notification', params.id], (signal) =>
        api.notice(organization.id, params.id, signal),
    );
    return (
        <QueryScreen title="notifications" query={query}>
            {({ data: n }) => (
                <>
                    <Card>
                        <Heading>{n.title}</Heading>
                        <Body>{n.message}</Body>
                        {!n.current && <Body>{t('noticeOld')}</Body>}
                    </Card>
                    <ActionFeedback action={action} />
                    {!n.read_at && (
                        <Button
                            label={t('read')}
                            disabled={action.busy}
                            onPress={() =>
                                void action.run(() =>
                                    api.read(organization.id, [n.id]),
                                )
                            }
                        />
                    )}
                </>
            )}
        </QueryScreen>
    );
}
export function Home() {
    const { api, organization } = useMember();
    const { t } = useLocale();
    const nav = useNav();
    const query = useMemberQuery(['dashboard'], async (signal) => {
        const [sessions, memberships, waiting, notices, attendance] =
            await Promise.all([
                api.sessions(organization.id, 1, 'reserved', undefined, signal),
                api.memberships(organization.id, 1, signal),
                api.waiting(organization.id, 1, signal),
                api.notices(organization.id, 1, signal),
                api.attendance(organization.id, 1, signal),
            ]);
        return { sessions, memberships, waiting, notices, attendance };
    });
    return (
        <QueryScreen title="home" query={query}>
            {(data) => (
                <>
                    <Heading>{organization.name}</Heading>
                    <View style={styles.row}>
                        {(
                            [
                                ['CheckIn', 'checkIn'],
                                ['Sessions', 'sessions'],
                                ['Groups', 'groups'],
                                ['Activity', 'activity'],
                                ['Notifications', 'notifications'],
                                ['Profile', 'profile'],
                            ] as const
                        ).map(([screen, label]) => (
                            <Button
                                key={screen}
                                label={t(label)}
                                onPress={() => nav.navigate(screen)}
                            />
                        ))}
                    </View>
                    <Body>
                        {t('unread')}: {data.notices.unread_count ?? 0}
                    </Body>
                    <Heading>{t('upcoming')}</Heading>
                    {data.sessions.data.length ? (
                        data.sessions.data
                            .slice(0, 3)
                            .map((s) => <SessionCard key={s.id} session={s} />)
                    ) : (
                        <Body>{t('noReservation')}</Body>
                    )}
                    <Heading>{t('membership')}</Heading>
                    {data.memberships.data.find(
                        (m) => m.status === 'active',
                    ) ? (
                        <MembershipCard
                            membership={data.memberships.data.find(
                                (m) => m.status === 'active',
                            )!}
                        />
                    ) : (
                        <Body>{t('noMembership')}</Body>
                    )}
                    <Heading>{t('waiting')}</Heading>
                    {data.waiting.data.slice(0, 3).map((w) => (
                        <Body key={w.id}>
                            {w.session_name}: {t(statusKey(w.status))}
                        </Body>
                    ))}
                    <Button
                        label={t('waiting')}
                        onPress={() => nav.navigate('Waiting')}
                    />
                    <Heading>{t('attendance')}</Heading>
                    {data.attendance.data.slice(0, 3).map((a) => (
                        <Body key={a.id}>
                            {a.session.name}: {t(statusKey(a.status))}
                        </Body>
                    ))}
                </>
            )}
        </QueryScreen>
    );
}
