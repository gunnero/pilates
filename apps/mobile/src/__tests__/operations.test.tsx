import {
    render,
    screen,
    fireEvent,
    waitFor,
    act,
} from '@testing-library/react-native';
import { AppState, type AppStateStatus } from 'react-native';
import { SafeAreaProvider } from 'react-native-safe-area-context';
import { LocaleProvider } from '../locales/context';
import { StaffOperations } from '../screens/StaffOperations';
import { staffCredentials } from '../storage/credentials';
import { OperationsClient } from '../api/operations';
jest.mock(
    'react-native-safe-area-context',
    () =>
        jest.requireActual('react-native-safe-area-context/jest/mock').default,
);
jest.mock('../storage/credentials', () => ({
    staffCredentials: {
        read: jest.fn(async () => null),
        write: jest.fn(async () => {}),
        clear: jest.fn(async () => {}),
    },
}));
const session = {
    id: 's',
    group: { id: 'g', name: 'Pilates' },
    trainer: { id: 't', display_name: 'Trainer' },
    location: { id: 'l', name: 'Studio' },
    local_date: '2026-10-01',
    start_time: '10:00',
    end_time: '11:00',
    phase: 'upcoming',
    status: 'scheduled',
    capacity: 12,
    reserved_count: 1,
    available_spots: 11,
    waiting_count: 0,
    is_overridden: false,
    attendance: { present: 0, pending: 1 },
    actions: { present: true, absence: false, correct: false, walk_in: false },
};
const meta = { current_page: 1, last_page: 1, total: 1 };
let calls: string[] = [];
let tokens: string[] = [];
beforeEach(() => {
    jest.clearAllMocks();
    calls = [];
    tokens = [];
    globalThis.fetch = jest.fn(
        async (url: RequestInfo | URL, init?: RequestInit) => {
            calls.push(String(url));
            tokens.push(
                (init?.headers as Record<string, string> | undefined)
                    ?.Authorization ?? '',
            );
            const path = String(url);
            let data: unknown = {
                data: { organizations: [{ id: 'o', name: 'Staff studio' }] },
            };
            if (path.endsWith('/staff-auth/token'))
                data = { data: { token: 'new-staff-token' } };
            if (path.includes('/context'))
                data = {
                    data: {
                        permissions: [
                            'sessions.view',
                            'attendance.view',
                            'attendance.manage',
                        ],
                    },
                };
            if (path.includes('/operations?'))
                data = {
                    data: [session],
                    today: '2026-10-01',
                    timezone: 'Europe/Skopje',
                    as_of: '2026-10-01T08:00:00Z',
                    meta,
                    summary: { reserved: 1, present: 0 },
                    alerts: [],
                    communication: null,
                };
            if (path.endsWith('/roster'))
                data = {
                    data: [
                        {
                            id: 'r',
                            member: {
                                id: 'm',
                                display_name: 'Synthetic member',
                            },
                            status: 'active',
                            position: null,
                            attendance: null,
                        },
                    ],
                    session,
                    meta,
                    as_of: '2026-10-01T08:00:00Z',
                };
            if (path.includes('/members/'))
                data = {
                    data: {
                        display_name: 'Synthetic member',
                        finance_access: false,
                    },
                };
            return {
                ok: true,
                status: 200,
                json: async () => data,
            } as Response;
        },
    );
});
function setup() {
    return render(
        <SafeAreaProvider>
            <LocaleProvider>
                <StaffOperations
                    origin="https://example.test"
                    exit={jest.fn()}
                />
            </LocaleProvider>
        </SafeAreaProvider>,
    );
}
it('restores staff credentials, opens roster and keeps finance restricted', async () => {
    jest.mocked(staffCredentials.read).mockResolvedValue('staff-token');
    setup();
    fireEvent.press(await screen.findByText('Staff studio'));
    fireEvent.press(await screen.findByText('Учесници'));
    expect(await screen.findByText('Synthetic member')).toBeTruthy();
    fireEvent.press(screen.getByText('Краток профил'));
    expect(
        await screen.findByText(
            'За информации за членарина и плаќање е потребна посебна дозвола.',
        ),
    ).toBeTruthy();
    expect(calls.some((p) => p.includes('/member/organizations'))).toBe(false);
});
it('uses existing attendance endpoint and refreshes canonical roster', async () => {
    jest.mocked(staffCredentials.read).mockResolvedValue('staff-token');
    setup();
    fireEvent.press(await screen.findByText('Staff studio'));
    fireEvent.press(await screen.findByText('Учесници'));
    fireEvent.press(await screen.findByText('Присутен'));
    await waitFor(() =>
        expect(
            calls.filter((p) => p.endsWith('/roster')).length,
        ).toBeGreaterThan(1),
    );
    expect(calls).toContain(
        'https://example.test/api/v1/organizations/o/sessions/s/attendance',
    );
});
it('logout clears secure staff credentials and visible context', async () => {
    jest.mocked(staffCredentials.read).mockResolvedValue('staff-token');
    setup();
    await screen.findByText('Staff studio');
    fireEvent.press(screen.getByText('Одјави се'));
    await waitFor(() => expect(staffCredentials.clear).toHaveBeenCalled());
    expect(screen.queryByText('Staff studio')).toBeNull();
});
it('serializes double actions and invalidates old response generations', () => {
    const client = new OperationsClient('https://example.test');
    const generation = client.generation;
    expect(client.begin()).toBe(true);
    expect(client.begin()).toBe(false);
    client.invalidate();
    expect(client.generation).not.toBe(generation);
    client.end();
    expect(client.begin()).toBe(true);
});

it('ignores a delayed old credential restore after a new sign-in', async () => {
    let resolve!: (value: string | null) => void;
    jest.mocked(staffCredentials.read).mockReturnValueOnce(
        new Promise((r) => {
            resolve = r;
        }),
    );
    setup();
    fireEvent.changeText(screen.getByLabelText('Е-пошта'), 'new@example.test');
    fireEvent.changeText(
        screen.getByLabelText('Лозинка'),
        'Fixture-password-123!',
    );
    fireEvent.press(screen.getByText('Најава'));
    await screen.findByText('Staff studio');
    await act(async () => {
        resolve('old-account-token');
    });
    expect(tokens).toContain('Bearer new-staff-token');
    expect(tokens).not.toContain('Bearer old-account-token');
    expect(staffCredentials.write).toHaveBeenCalledWith('new-staff-token');
});

it('drops an old profile response when the app resumes and revalidates scope', async () => {
    let resume!: (state: AppStateStatus) => void;
    const listener = jest
        .spyOn(AppState, 'addEventListener')
        .mockImplementation((_event, callback) => {
            resume = callback;
            return { remove: () => {} };
        });
    jest.mocked(staffCredentials.read).mockResolvedValue('staff-token');
    const original = globalThis.fetch;
    let resolve!: (value: Response) => void;
    globalThis.fetch = jest.fn((url: RequestInfo | URL, init?: RequestInit) =>
        String(url).includes('/members/')
            ? new Promise<Response>((r) => {
                  resolve = r;
              })
            : original(url, init),
    );
    try {
        setup();
        fireEvent.press(await screen.findByText('Staff studio'));
        fireEvent.press(await screen.findByText('Учесници'));
        fireEvent.press(await screen.findByText('Краток профил'));
        await act(async () => {
            resume('active');
        });
        await act(async () => {
            resolve({
                ok: true,
                status: 200,
                json: async () => ({
                    data: {
                        display_name: 'Obsolete private profile',
                        finance_access: false,
                    },
                }),
            } as Response);
        });
        expect(screen.queryByText('Obsolete private profile')).toBeNull();
        await waitFor(() =>
            expect(
                calls.filter((p) => p.endsWith('/roster')).length,
            ).toBeGreaterThan(1),
        );
    } finally {
        listener.mockRestore();
    }
});
