import {
    render,
    screen,
    fireEvent,
    waitFor,
    act,
} from '@testing-library/react-native';
import { SafeAreaProvider } from 'react-native-safe-area-context';
import { LocaleProvider } from '../locales/context';
import { StaffReports } from '../screens/StaffReports';
import { OperationsClient } from '../api/operations';
import { shareReport } from '../api/report-export';
jest.mock(
    'react-native-safe-area-context',
    () =>
        jest.requireActual('react-native-safe-area-context/jest/mock').default,
);
jest.mock('../api/report-export', () => ({
    shareReport: jest.fn(async () => {}),
    clearReportFiles: jest.fn(),
}));
const response = {
    data: {
        meta: {
            from: '2026-10-01',
            until: '2026-10-12',
            timezone: 'Europe/Skopje',
            as_of: '2026-10-12T12:00:00Z',
            today: '2026-10-12',
            basis: 'restated_original_payment_period',
        },
        totals: { net_minor: '4000' },
        rows: [],
        columns: ['period'],
        options: {},
        comparison: null,
    },
};
let client: OperationsClient;
beforeEach(() => {
    jest.clearAllMocks();
    client = new OperationsClient('https://example.test');
    client.setToken('staff');
    jest.spyOn(client, 'request').mockImplementation(async (path) =>
        path.endsWith('/context')
            ? {
                  data: {
                      permissions: [
                          'reports.view',
                          'payments.view',
                          'sessions.view',
                          'attendance.view',
                      ],
                      role: 'owner',
                      location_scope: 'all',
                  },
              }
            : response,
    );
});
function mount() {
    const view = render(
        <SafeAreaProvider>
            <LocaleProvider>
                <StaffReports
                    client={client}
                    organization="o"
                    exit={() => {}}
                />
            </LocaleProvider>
        </SafeAreaProvider>,
    );
    fireEvent.press(screen.getByText('English'));
    return view;
}
it('renders integrated report with server dates and exports', async () => {
    mount();
    await screen.findByText(/Net \(MKD\):.*40.00/);
    fireEvent.press(screen.getByText('Download Excel'));
    await waitFor(() =>
        expect(shareReport).toHaveBeenCalledWith(
            client,
            expect.stringContaining('report=revenue'),
            'xlsx',
            expect.anything(),
        ),
    );
});
it('denies member-like context without fetching protected data', async () => {
    jest.mocked(client.request).mockResolvedValue({
        data: { permissions: [], role: 'staff', location_scope: 'all' },
    });
    mount();
    await screen.findByText(
        'Access is no longer available. Choose a studio again.',
    );
    expect(client.request).toHaveBeenCalledTimes(1);
});
it('clears old financial values after a revoked refresh', async () => {
    mount();
    await screen.findByText(/Net \(MKD\):.*40.00/);
    jest.mocked(client.request).mockRejectedValue(new Error('revoked'));
    fireEvent.press(screen.getByText('Refresh'));
    await waitFor(() =>
        expect(screen.queryByText(/Net \(MKD\):.*40.00/)).toBeNull(),
    );
});
it('does not repopulate after unmount', async () => {
    let resolve!: (v: unknown) => void;
    jest.mocked(client.request).mockImplementation(
        () =>
            new Promise((r) => {
                resolve = r;
            }),
    );
    const view = mount();
    view.unmount();
    await act(async () => resolve(response));
    expect(shareReport).not.toHaveBeenCalled();
});
it('uses the authoritative local date for presets and keeps custom comparison input explicit', async () => {
    mount();
    await screen.findByText(/Net \(MKD\):.*40.00/);
    fireEvent.press(screen.getByText('Last 7 days'));
    await waitFor(() =>
        expect(client.request).toHaveBeenCalledWith(
            expect.stringContaining('from=2026-10-06'),
            'GET',
            undefined,
            expect.anything(),
        ),
    );
    await screen.findByText(/Net \(MKD\):.*40.00/);
    fireEvent.press(screen.getByText('Custom period'));
    await screen.findByText('Choose both comparison dates, then apply.');
});
