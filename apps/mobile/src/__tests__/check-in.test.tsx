import {
    render,
    screen,
    fireEvent,
    waitFor,
    act,
} from '@testing-library/react-native';
import { QueryClientProvider } from '@tanstack/react-query';
import { SafeAreaProvider } from 'react-native-safe-area-context';
import { LocaleProvider } from '../locales/context';
import { SessionProvider } from '../auth/context';
import { SessionController } from '../auth/session';
import { MemberApi } from '../api/member';
import { ApiClient } from '../api/client';
import { createCache } from '../hooks/cache';
import { CheckIn } from '../screens/CheckIn';
import { parseCheckIn } from '../check-in/parse';
import { ApiError } from '../api/client';
import { AppState, View as MockView } from 'react-native';
import type { ReactElement } from 'react';
const mockNavigate = jest.fn();
jest.mock('@react-navigation/native', () => ({
    useIsFocused: () => true,
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
let mockPermission = { granted: false, canAskAgain: true };
const mockRequestPermission = jest.fn(async () => ({ granted: true }));
let mockScan: ((event: { data: string }) => void) | undefined;
jest.mock('expo-camera', () => ({
    useCameraPermissions: () => [mockPermission, mockRequestPermission],
    CameraView: (props: { onBarcodeScanned: typeof mockScan }) => {
        mockScan = props.onBarcodeScanned;
        return <MockView testID="camera" />;
    },
}));
const payload = (organization = org) =>
    'GTQR1:' +
    JSON.stringify({
        organization,
        session: id,
        credential: 'Y3JlZGVudGlhbA==',
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
beforeEach(() => {
    AppState.currentState = 'active';
    mockPermission = { granted: false, canAskAgain: true };
    mockScan = undefined;
    jest.clearAllMocks();
});
test.each([
    'https://attacker.invalid',
    '<script>alert(1)</script>',
    'GTQR2:{}',
    'GTQR1:null',
    'GTQR1:[]',
    'GTQR1:{}',
    'GTQR1:' + 'A'.repeat(4096),
    payload().replace('Y3JlZGVudGlhbA==', '<bad>'),
])('rejects unsafe QR input %#', (raw) => {
    expect(parseCheckIn(raw)).toBeNull();
});
test('accepts only bounded versioned studio/session credential envelope', () => {
    expect(parseCheckIn(payload())).toEqual({
        organization: org,
        session: id,
        credential: 'Y3JlZGVudGlhbA==',
    });
    expect(
        parseCheckIn(
            payload().replace(
                '"credential":',
                '"member_id":"forged","credential":',
            ),
        ),
    ).toBeNull();
});
test('requests permission contextually and supplies manual fallback for restricted camera', async () => {
    mockPermission = { granted: false, canAskAgain: false };
    await setup(<CheckIn />);
    expect(mockRequestPermission).not.toHaveBeenCalled();
    expect(screen.getByText(/Камерата не е достапна/)).toBeTruthy();
    expect(screen.queryByTestId('camera')).toBeNull();
    expect(
        screen.queryByRole('button', { name: 'Овозможи камера' }),
    ).toBeNull();
});
test('opens granted camera only after contextual action, suppresses duplicates and waits for server success', async () => {
    mockPermission.granted = true;
    const { api } = await setup(<CheckIn />);
    let finish!: (v: unknown) => void;
    const check = jest.spyOn(api, 'checkIn').mockImplementation(
        () =>
            new Promise((resolve) => {
                finish = resolve as (v: unknown) => void;
            }),
    );
    expect(screen.queryByTestId('camera')).toBeNull();
    fireEvent.press(screen.getByRole('button', { name: 'Скенирај повторно' }));
    await waitFor(() => expect(screen.getByTestId('camera')).toBeTruthy());
    await act(async () => {
        mockScan?.({ data: payload() });
        mockScan?.({ data: payload() });
    });
    expect(check).toHaveBeenCalledTimes(1);
    expect(screen.queryByText('Присуството е потврдено')).toBeNull();
    await act(async () =>
        finish({
            data: {
                outcome: 'checked_in',
                attendance: { status: 'present' },
                session: {
                    id,
                    name: 'Pilates',
                    starts_at: '2026-10-01T08:00:00Z',
                    timezone: 'Europe/Skopje',
                },
            },
        }),
    );
    expect(screen.getByText('Присуството е потврдено')).toBeTruthy();
    expect(screen.queryByTestId('camera')).toBeNull();
});
test.each([
    'qr_expired',
    'qr_invalid',
    'too_early',
    'too_late',
    'waiting',
    'reservation_required',
    'cancelled',
    'correction_required',
])('shows a bounded localized server rejection: %s', async (code) => {
    mockPermission.granted = true;
    const { api } = await setup(<CheckIn />);
    jest.spyOn(api, 'checkIn').mockRejectedValue(
        new ApiError('conflict', code),
    );
    fireEvent.press(screen.getByRole('button', { name: 'Скенирај повторно' }));
    await waitFor(() => expect(screen.getByTestId('camera')).toBeTruthy());
    await act(async () => mockScan?.({ data: payload() }));
    expect(screen.getByRole('alert')).toBeTruthy();
    expect(screen.queryByText('Присуството е потврдено')).toBeNull();
});
test('foreign organization and malformed scans never submit', async () => {
    mockPermission.granted = true;
    const { api } = await setup(<CheckIn />);
    const check = jest.spyOn(api, 'checkIn');
    for (const data of ['https://attacker.invalid', payload(id)]) {
        fireEvent.press(
            screen.getByRole('button', { name: 'Скенирај повторно' }),
        );
        await waitFor(() => expect(screen.getByTestId('camera')).toBeTruthy());
        await act(async () => mockScan?.({ data }));
        expect(screen.getByRole('alert')).toBeTruthy();
    }
    expect(check).not.toHaveBeenCalled();
});
test('ambiguous network failure requires checking authoritative session, never automatic resubmission', async () => {
    mockPermission.granted = true;
    const { api } = await setup(<CheckIn />);
    const check = jest
        .spyOn(api, 'checkIn')
        .mockRejectedValue(new ApiError('network'));
    fireEvent.press(screen.getByRole('button', { name: 'Скенирај повторно' }));
    await waitFor(() => expect(screen.getByTestId('camera')).toBeTruthy());
    await act(async () => mockScan?.({ data: payload() }));
    expect(screen.getByText(/Барањето можеби успеало/)).toBeTruthy();
    expect(
        screen.queryByRole('button', { name: 'Скенирај повторно' }),
    ).toBeNull();
    fireEvent.press(
        screen.getByRole('button', { name: 'Провери присуство во терминот' }),
    );
    expect(mockNavigate).toHaveBeenCalledWith('Session', { id });
    expect(check).toHaveBeenCalledTimes(1);
});
test('denied permission does not mount a camera and retains trainer fallback', async () => {
    mockRequestPermission.mockResolvedValueOnce({ granted: false });
    await setup(<CheckIn />);
    fireEvent.press(screen.getByRole('button', { name: 'Овозможи камера' }));
    await waitFor(() => expect(mockRequestPermission).toHaveBeenCalledTimes(1));
    expect(screen.queryByTestId('camera')).toBeNull();
    expect(screen.getByText(/Камерата не е достапна/)).toBeTruthy();
});
test('already-present result is server-derived and stale camera callbacks after unmount cannot submit', async () => {
    mockPermission.granted = true;
    const { api, unmount } = await setup(<CheckIn />);
    const check = jest.spyOn(api, 'checkIn').mockResolvedValue({
        data: {
            outcome: 'already_present',
            attendance: {
                id,
                status: 'present',
                method: 'staff',
                checked_in_at: '2026-10-01T08:00:00Z',
            },
            session: {
                id,
                name: 'Pilates',
                starts_at: '2026-10-01T08:00:00Z',
                timezone: 'Europe/Skopje',
            },
        },
    });
    fireEvent.press(screen.getByRole('button', { name: 'Скенирај повторно' }));
    await waitFor(() => expect(screen.getByTestId('camera')).toBeTruthy());
    await act(async () => mockScan?.({ data: payload() }));
    expect(screen.getByText('Веќе сте пријавени')).toBeTruthy();
    fireEvent.press(screen.getByRole('button', { name: 'Скенирај повторно' }));
    await waitFor(() => expect(screen.getByTestId('camera')).toBeTruthy());
    unmount();
    await act(async () => mockScan?.({ data: payload() }));
    expect(check).toHaveBeenCalledTimes(1);
});
