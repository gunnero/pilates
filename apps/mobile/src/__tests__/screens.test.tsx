import {
    render,
    screen,
    fireEvent,
    waitFor,
    act,
} from '@testing-library/react-native';
import { QueryClientProvider } from '@tanstack/react-query';
import { SafeAreaProvider } from 'react-native-safe-area-context';
import { Alert } from 'react-native';
import { LocaleProvider } from '../locales/context';
import { SessionProvider } from '../auth/context';
import { SessionController } from '../auth/session';
import { MemberApi } from '../api/member';
import { ApiClient } from '../api/client';
import { createCache } from '../hooks/cache';
import { Auth } from '../screens/Auth';
import { Profile } from '../screens/Profile';
import {
    Home,
    Sessions,
    SessionDetail,
    WaitingList,
    AttendanceHistory,
    Memberships,
    Payments,
    Inbox,
    NoticeDetail,
} from '../screens/Domain';
import type { ReactElement } from 'react';
const mockNavigate = jest.fn();
jest.mock('@react-navigation/native', () => ({
    useNavigation: () => ({ navigate: mockNavigate }),
    useRoute: () => ({
        params: { id: '01950000-0000-7000-8000-000000000002' },
    }),
}));
jest.mock(
    'react-native-safe-area-context',
    () =>
        jest.requireActual('react-native-safe-area-context/jest/mock').default,
);
const org = '01950000-0000-7000-8000-000000000001';
const id = '01950000-0000-7000-8000-000000000002';
const session = {
    id,
    group: { id, name: 'Morning Pilates' },
    location: { id, name: 'Centar' },
    trainer: { id, name: 'Trainer' },
    starts_at: '2026-10-01T08:00:00Z',
    ends_at: '2026-10-01T09:00:00Z',
    timezone: 'Europe/Skopje',
    status: 'scheduled',
    available_spots: 1,
    capacity: 1,
    can_reserve: true,
    can_join_waiting: false,
    attendance_status: 'pending',
    reservation: null,
    waiting_entry: null,
};
const paged = (data: unknown[]) => ({
    data,
    meta: { current_page: 1, last_page: 2 },
});
async function setup(
    element: ReactElement,
    handler: (url: string, init?: RequestInit) => unknown = () => ({
        data: [],
    }),
    width = 320,
) {
    const transport = jest.fn(
        async (url: string | URL | Request, init?: RequestInit) => {
            const data = handler(String(url), init);
            return {
                ok: true,
                status: 200,
                json: async () => data,
            } as Response;
        },
    );
    const api = new MemberApi(
        new ApiClient('https://studio.example', transport),
    );
    const cache = createCache();
    const controller = new SessionController(
        api,
        {
            read: async () => 'token',
            write: async () => {},
            clear: async () => {},
        },
        () => cache.clear(),
        async () => {},
    );
    transport.mockImplementationOnce(
        async () =>
            ({
                ok: true,
                status: 200,
                json: async () => ({
                    data: {
                        id: 'A',
                        name: 'Member',
                        email: 'member@example.test',
                    },
                }),
            }) as Response,
    );
    await controller.restore();
    transport.mockImplementationOnce(
        async () =>
            ({
                ok: true,
                status: 200,
                json: async () => ({ data: {} }),
            }) as Response,
    );
    await controller.select({
        id: org,
        name: 'Studio A',
        timezone: 'Europe/Skopje',
        locale: 'mk',
        currency: 'MKD',
    });
    const view = render(
        <SafeAreaProvider
            initialMetrics={{
                frame: { x: 0, y: 0, width, height: 700 },
                insets: { top: 0, bottom: 0, left: 0, right: 0 },
            }}
        >
            <LocaleProvider>
                <QueryClientProvider client={cache}>
                    <SessionProvider controller={controller}>
                        {element}
                    </SessionProvider>
                </QueryClientProvider>
            </LocaleProvider>
        </SafeAreaProvider>,
    );
    return { transport, api, controller, cache, ...view };
}
afterEach(() => jest.restoreAllMocks());
test.each([320, 430])(
    'dashboard exposes member navigation and server values at width %s',
    async (width) => {
        const test = await setup(
            <Home />,
            (url) =>
                url.includes('/sessions?')
                    ? paged([session])
                    : url.includes('/notifications?')
                      ? { ...paged([]), unread_count: 3 }
                      : paged([]),
            width,
        );
        expect(await screen.findByText('Morning Pilates')).toBeTruthy();
        expect(screen.getByText('Непрочитани: 3')).toBeTruthy();
        expect(
            screen.getByText('Нема активна членарина во овој преглед.'),
        ).toBeTruthy();
        fireEvent.press(screen.getByRole('button', { name: 'Термини' }));
        expect(mockNavigate).toHaveBeenCalledWith('Sessions');
        test.unmount();
        test.cache.clear();
    },
);
test('discovery renders server availability and paginates bounded queries', async () => {
    const test = await setup(<Sessions />, () => paged([session]));
    expect(await screen.findByText('Слободни места: 1')).toBeTruthy();
    fireEvent.press(screen.getByRole('button', { name: 'Следна' }));
    await waitFor(() =>
        expect(test.transport).toHaveBeenCalledWith(
            expect.stringContaining('page=2'),
            expect.anything(),
        ),
    );
    test.unmount();
    test.cache.clear();
});
test('booking confirms through server then refetches; no optimistic reservation', async () => {
    let reserved = false;
    const test = await setup(<SessionDetail />, (_url, init) => {
        if (init?.method === 'POST') {
            reserved = true;
            return { data: { outcome: 'reserved' } };
        }
        return {
            data: {
                ...session,
                can_reserve: !reserved,
                reservation: reserved
                    ? { id, status: 'active', can_cancel: true }
                    : null,
            },
        };
    });
    fireEvent.press(await screen.findByRole('button', { name: 'Резервирај' }));
    await waitFor(() =>
        expect(screen.getByText('Ваша резервација: Активна')).toBeTruthy(),
    );
    expect(
        test.transport.mock.calls.filter(([, init]) => init?.method === 'POST'),
    ).toHaveLength(2);
    test.unmount();
    test.cache.clear();
});
test('full session permits only server-offered waiting action', async () => {
    const test = await setup(<SessionDetail />, () => ({
        data: {
            ...session,
            available_spots: 0,
            can_reserve: false,
            can_join_waiting: true,
        },
    }));
    expect(
        await screen.findByRole('button', {
            name: 'Приклучи се на листа на чекање',
        }),
    ).toBeTruthy();
    expect(screen.queryByRole('button', { name: 'Резервирај' })).toBeNull();
    test.unmount();
    test.cache.clear();
});
test('cancellation requires destructive confirmation', async () => {
    const alert = jest.spyOn(Alert, 'alert');
    const test = await setup(<SessionDetail />, () => ({
        data: {
            ...session,
            can_reserve: false,
            reservation: { id, status: 'active', can_cancel: true },
        },
    }));
    fireEvent.press(
        await screen.findByRole('button', { name: 'Откажи резервација' }),
    );
    expect(alert).toHaveBeenCalledWith(
        'Потврди откажување',
        expect.any(String),
        expect.arrayContaining([
            expect.objectContaining({ style: 'destructive' }),
        ]),
    );
    expect(
        test.transport.mock.calls.filter(([url]) =>
            String(url).includes('/cancel'),
        ),
    ).toHaveLength(0);
    test.unmount();
    test.cache.clear();
});
test.each([
    [
        'waiting',
        <WaitingList />,
        [
            {
                id,
                status: 'waiting',
                session_id: id,
                session_name: 'My waiting session',
                starts_at: session.starts_at,
                timezone: session.timezone,
            },
        ],
        'My waiting session',
    ],
    [
        'attendance',
        <AttendanceHistory />,
        [
            {
                id,
                status: 'present',
                session: {
                    id,
                    name: 'Attended session',
                    starts_at: session.starts_at,
                    timezone: session.timezone,
                },
            },
        ],
        'Присутен',
    ],
    [
        'memberships',
        <Memberships />,
        [
            {
                id,
                plan_name: 'Monthly',
                starts_on: '2026-10-01',
                ends_on: '2026-11-01',
                status: 'active',
                agreed_price_minor: 10000,
                net_paid_minor: 5000,
                balance_minor: 5000,
                currency: 'MKD',
            },
        ],
        'Monthly',
    ],
    [
        'payments',
        <Payments />,
        [
            {
                id,
                amount_minor: 5000,
                currency: 'MKD',
                paid_at: session.starts_at,
                status: 'reversed',
                reversal: null,
            },
        ],
        'Сторнирано',
    ],
] as const)(
    '%s renders own server history read-only',
    async (_name, element, data, text) => {
        const test = await setup(element, () => paged([...data]));
        expect(await screen.findByText(text)).toBeTruthy();
        expect(screen.queryByRole('button', { name: 'Зачувано.' })).toBeNull();
        test.unmount();
        test.cache.clear();
    },
);
test('inbox supports unread count and bounded page read', async () => {
    const test = await setup(<Inbox />, () => ({
        ...paged([{ id, title: 'Notice', read_at: null }]),
        unread_count: 1,
    }));
    fireEvent.press(
        await screen.findByRole('button', {
            name: 'Означи ја страницата како прочитана',
        }),
    );
    await waitFor(() =>
        expect(test.transport).toHaveBeenCalledWith(
            expect.stringContaining('/notifications/read'),
            expect.objectContaining({ body: JSON.stringify({ ids: [id] }) }),
        ),
    );
    test.unmount();
    test.cache.clear();
});
test('notification detail shows historical relevance without following arbitrary URLs', async () => {
    const test = await setup(<NoticeDetail />, () => ({
        data: {
            id,
            title: 'Past event',
            message: 'Review current session',
            current: false,
            read_at: null,
        },
    }));
    expect(await screen.findByText('Past event')).toBeTruthy();
    expect(screen.getByText(/Известувањето се однесува/)).toBeTruthy();
    test.unmount();
    test.cache.clear();
});
test('authentication clears password after failure and provides localized error', async () => {
    const test = await setup(<Auth link={null} consumed={() => {}} />);
    test.transport.mockResolvedValue({
        ok: false,
        status: 422,
        json: async () => ({ trace: 'private' }),
    } as Response);
    fireEvent.changeText(
        screen.getByLabelText('Е-пошта'),
        'member@example.test',
    );
    fireEvent.changeText(screen.getByLabelText('Лозинка'), 'secret');
    await act(async () => {
        fireEvent.press(screen.getByRole('button', { name: 'Најава' }));
    });
    expect(screen.getByLabelText('Лозинка').props.value).toBe('');
    expect(screen.getByRole('alert')).toBeTruthy();
    test.unmount();
    test.cache.clear();
});

test('duplicate reservation taps issue one mutation while pending', async () => {
    const test = await setup(<SessionDetail />, () => ({ data: session }));
    const button = await screen.findByRole('button', { name: 'Резервирај' });
    let finish!: (response: Response) => void;
    test.transport.mockImplementationOnce(
        () =>
            new Promise((resolve) => {
                finish = resolve;
            }),
    );
    fireEvent.press(button);
    fireEvent.press(button);
    expect(
        test.transport.mock.calls.filter(([url]) =>
            String(url).endsWith('/reservations'),
        ),
    ).toHaveLength(1);
    await act(async () => {
        finish({
            ok: true,
            status: 200,
            json: async () => ({ data: { outcome: 'reserved' } }),
        } as Response);
    });
    test.unmount();
    test.cache.clear();
});
test('conflict refreshes availability and exposes safe localized error', async () => {
    const test = await setup(<SessionDetail />, () => ({ data: session }));
    const button = await screen.findByRole('button', { name: 'Резервирај' });
    test.transport.mockImplementationOnce(
        async () =>
            ({
                ok: false,
                status: 409,
                json: async () => ({ trace: 'private' }),
            }) as Response,
    );
    test.transport.mockImplementationOnce(
        async () =>
            ({
                ok: true,
                status: 200,
                json: async () => ({
                    data: {
                        ...session,
                        available_spots: 0,
                        can_reserve: false,
                        can_join_waiting: true,
                    },
                }),
            }) as Response,
    );
    fireEvent.press(button);
    expect(
        await screen.findByText(
            'Терминот е променет. Освежете за тековна достапност.',
        ),
    ).toBeTruthy();
    expect(
        screen.getByRole('button', { name: 'Приклучи се на листа на чекање' }),
    ).toBeTruthy();
    expect(screen.queryByText('private')).toBeNull();
    test.unmount();
    test.cache.clear();
});
test('ambiguous network mutation refetches without repeating POST', async () => {
    const test = await setup(<SessionDetail />, () => ({ data: session }));
    const button = await screen.findByRole('button', { name: 'Резервирај' });
    test.transport.mockRejectedValueOnce(new Error('offline'));
    fireEvent.press(button);
    expect(await screen.findByText(/Барањето можеби стигнало/)).toBeTruthy();
    expect(
        test.transport.mock.calls.filter(([url]) =>
            String(url).endsWith('/reservations'),
        ),
    ).toHaveLength(1);
    test.unmount();
    test.cache.clear();
});
test('read failure hides old financial values and offers an explicit retry', async () => {
    const test = await setup(<Payments />, () => paged([]));
    expect(await screen.findByText('Сè уште нема податоци.')).toBeTruthy();
    test.transport.mockResolvedValue({
        ok: false,
        status: 500,
        json: async () => ({}),
    } as Response);
    await act(async () => {
        await test.cache.invalidateQueries();
    });
    expect(
        await screen.findByRole('button', { name: 'Обиди се повторно' }),
    ).toBeTruthy();
    expect(screen.queryByText('Сè уште нема податоци.')).toBeNull();
    test.unmount();
    test.cache.clear();
});

test('removed studio link leaves an accessible way to clear context after 404', async () => {
    const test = await setup(<Home />);
    await screen.findByText('Нема претстојна резервација.');
    test.transport.mockResolvedValue({
        ok: false,
        status: 404,
        json: async () => ({}),
    } as Response);
    await act(async () => {
        await test.cache.invalidateQueries();
    });
    const leave = jest
        .spyOn(test.controller, 'leaveOrganization')
        .mockImplementation(() => {});
    fireEvent.press(
        await screen.findByRole('button', { name: 'Промени студио' }),
    );
    expect(leave).toHaveBeenCalledTimes(1);
    test.unmount();
    test.cache.clear();
});

test('profile respects locale and saves notification preferences through the server', async () => {
    let reminders = true;
    const test = await setup(<Profile />, (url, init) => {
        if (url.endsWith('/preferences')) {
            if (init?.method === 'PATCH')
                reminders = JSON.parse(String(init.body)).reminders_enabled;
            return {
                data: {
                    reminders_enabled: reminders,
                    expiry_reminders_enabled: true,
                },
            };
        }
        return {
            data: {
                id,
                first_name: 'Member',
                last_name: 'Profile',
                email: 'member@example.test',
                phone: null,
                preferred_locale: 'en',
            },
        };
    });
    const toggle = await screen.findByRole('button', {
        name: 'Session reminders: Enabled',
    });
    fireEvent.press(toggle);
    expect(
        await screen.findByRole('button', {
            name: 'Session reminders: Disabled',
        }),
    ).toBeTruthy();
    fireEvent.press(
        screen.getByRole('button', { name: 'Enable push notifications' }),
    );
    expect(
        await screen.findByText(
            'Push delivery is not configured. Your in-app inbox remains available.',
        ),
    ).toBeTruthy();
    test.unmount();
    test.cache.clear();
});
