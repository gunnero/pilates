import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import {
    act,
    fireEvent,
    render,
    screen,
    waitFor,
} from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter } from 'react-router-dom';
import { AttendanceRoster, AttendanceToday } from './Attendance';
import {
    api,
    ApiError,
    type Organization,
    type TrainingSession,
} from '../lib/api';
import i18n from '../lib/i18n';
vi.mock('../lib/api', async (original) => ({
    ...(await original<typeof import('../lib/api')>()),
    api: { get: vi.fn(), post: vi.fn() },
}));
const org: Organization = {
    id: 'org',
    name: 'Studio',
    slug: 's',
    status: 'active',
    locale: 'en',
    timezone: 'Europe/Skopje',
    currency: 'MKD',
    membership_id: 'staff',
    location_scope: 'selected',
    permissions: [
        'sessions.view',
        'attendance.view',
        'attendance.manage',
        'attendance.correct',
        'reservations.view',
        'reservations.manage',
    ],
};
const session: TrainingSession = {
    id: 's',
    group: { id: 'g', name: 'Pilates' },
    location: { id: 'l', name: 'Centar' },
    trainer: { id: 't', display_name: 'Trainer' },
    local_date: '2026-09-22',
    start_time: '18:00',
    end_time: '19:00',
    starts_at: '2099-01-01T18:00:00Z',
    ends_at: '2099-01-01T19:00:00Z',
    timezone: 'Europe/Skopje',
    capacity: 12,
    status: 'scheduled',
    source_type: 'manual',
    is_overridden: false,
    cancellation_reason: null,
    cancellation_kind: null,
    operational_issues: [],
    editable: true,
    attendance_open: true,
};
const row = {
    member: { id: 'm', display_name: 'Offline Member' },
    reservation_id: 'r',
    reservation_status: 'active',
    attendance: null,
};
const meta = { current_page: 1, last_page: 1, total: 1, per_page: 25 };
beforeEach(async () => {
    vi.resetAllMocks();
    await i18n.changeLanguage('en');
    vi.mocked(api.get).mockResolvedValue({ data: [row], meta });
    vi.mocked(api.post).mockResolvedValue({ data: {} });
});
it('shows today sessions and links to scoped session', async () => {
    vi.mocked(api.get).mockResolvedValue({ data: [session], meta });
    render(
        <MemoryRouter>
            <AttendanceToday organization={org} />
        </MemoryRouter>,
    );
    expect(
        await screen.findByRole('link', { name: 'Open session' }),
    ).toHaveAttribute('href', '/schedule/s');
});
it('marks offline member present in one click', async () => {
    const user = userEvent.setup();
    render(<AttendanceRoster organization={org} session={session} />);
    await user.click(
        await screen.findByRole('button', { name: 'Mark present' }),
    );
    expect(api.post).toHaveBeenCalledWith(
        '/api/v1/organizations/org/sessions/s/attendance',
        { member_id: 'm', status: 'present' },
    );
    expect(await screen.findByRole('status')).toHaveTextContent(
        'Attendance saved',
    );
});
it('marks no-show only on ended sessions', async () => {
    const user = userEvent.setup();
    render(
        <AttendanceRoster
            organization={org}
            session={{
                ...session,
                attendance_open: false,
                ends_at: '2020-01-01T00:00:00Z',
            }}
        />,
    );
    await user.click(
        await screen.findByRole('button', { name: 'Mark no-show' }),
    );
    expect(api.post).toHaveBeenCalledWith(expect.any(String), {
        member_id: 'm',
        status: 'no_show',
    });
    expect(
        screen.queryByRole('button', { name: 'Mark present' }),
    ).not.toBeInTheDocument();
});
it('corrects with explicit reason', async () => {
    const user = userEvent.setup();
    vi.mocked(api.get).mockResolvedValue({
        data: [
            {
                ...row,
                attendance: {
                    id: 'a',
                    status: 'no_show',
                    method: 'staff',
                    checked_in_at: null,
                },
            },
        ],
        meta,
    });
    render(<AttendanceRoster organization={org} session={session} />);
    await user.click(
        await screen.findByRole('button', { name: 'Correct attendance' }),
    );
    await user.click(screen.getByRole('button', { name: 'Save changes' }));
    expect(api.post).toHaveBeenCalledWith(expect.stringContaining('/correct'), {
        member_id: 'm',
        status: 'present',
        reason: 'mistake',
    });
});
it('walk-in searches minimal member then allocates and checks in', async () => {
    const user = userEvent.setup();
    vi.mocked(api.post).mockImplementation(async (path) =>
        path.endsWith('member-lookup')
            ? { data: [{ id: 'new', display_name: 'Walk In' }] }
            : { data: {} },
    );
    render(<AttendanceRoster organization={org} session={session} />);
    await user.type(screen.getByRole('textbox'), 'Walk');
    await user.click(screen.getByRole('button', { name: 'Search' }));
    await user.click(
        await screen.findByRole('button', { name: 'Add walk-in and check in' }),
    );
    expect(api.post).toHaveBeenCalledWith(expect.stringContaining('/walk-in'), {
        member_id: 'new',
    });
});
it('shows locally rendered expiring QR without a member endpoint', async () => {
    const user = userEvent.setup();
    vi.mocked(api.post).mockResolvedValue({
        data: {
            token: 'opaque-test-value',
            payload: 'GTQR1:opaque-test-envelope',
            server_time: new Date().toISOString(),
            expires_at: new Date(Date.now() + 90000).toISOString(),
            refresh_after: 60,
        },
    });
    render(<AttendanceRoster organization={org} session={session} />);
    await user.click(screen.getByRole('button', { name: 'Show check-in QR' }));
    await waitFor(() => expect(document.querySelector('svg')).not.toBeNull());
    expect(screen.getByText(/Member sign-in is required/)).toBeVisible();
    await user.click(screen.getByRole('button', { name: 'Hide QR' }));
    expect(document.querySelector('svg')).toBeNull();
});
it('does not load private roster without permission', () => {
    render(
        <AttendanceRoster
            organization={{ ...org, permissions: [] }}
            session={session}
        />,
    );
    expect(api.get).not.toHaveBeenCalled();
});
it('view-only hides marking corrections walk-in and QR', async () => {
    render(
        <AttendanceRoster
            organization={{ ...org, permissions: ['attendance.view'] }}
            session={session}
        />,
    );
    await screen.findByText('Offline Member');
    expect(
        screen.queryByRole('button', { name: 'Mark present' }),
    ).not.toBeInTheDocument();
    expect(screen.queryByRole('textbox')).not.toBeInTheDocument();
    expect(
        screen.queryByRole('button', { name: 'Show check-in QR' }),
    ).not.toBeInTheDocument();
});
it('surfaces validation and conflict errors', async () => {
    const user = userEvent.setup();
    vi.mocked(api.post).mockRejectedValue(
        new ApiError(409, 'Session is cancelled.'),
    );
    render(<AttendanceRoster organization={org} session={session} />);
    await user.click(
        await screen.findByRole('button', { name: 'Mark present' }),
    );
    expect(await screen.findByRole('alert')).toHaveTextContent(
        'Session is cancelled.',
    );
});
it('renders empty roster', async () => {
    vi.mocked(api.get).mockResolvedValue({ data: [], meta });
    render(<AttendanceRoster organization={org} session={session} />);
    expect(
        await screen.findByText('No reservations or attendance yet.'),
    ).toBeVisible();
});

afterEach(() => vi.useRealTimers());
it('polls a bounded roster and reflects server attendance without reload', async () => {
    vi.useFakeTimers();
    render(<AttendanceRoster organization={org} session={session} />);
    await act(async () => {});
    expect(api.get).toHaveBeenCalledTimes(1);
    vi.mocked(api.get).mockResolvedValue({
        data: [
            {
                ...row,
                attendance: {
                    id: 'a',
                    status: 'present',
                    method: 'qr',
                    checked_in_at: '2026-09-22T16:00:00Z',
                },
            },
        ],
        meta,
    });
    await act(async () => {
        await vi.advanceTimersByTimeAsync(10000);
    });
    expect(api.get).toHaveBeenCalledTimes(2);
    expect(screen.getByText(/Present.*QR/)).toBeVisible();
});
it('rotates QR and removes it after renewal failure', async () => {
    vi.useFakeTimers();
    vi.mocked(api.post).mockResolvedValue({
        data: {
            token: 'test',
            payload: 'GTQR1:envelope',
            server_time: new Date().toISOString(),
            expires_at: new Date(Date.now() + 90000).toISOString(),
            refresh_after: 30,
        },
    });
    render(<AttendanceRoster organization={org} session={session} />);
    await act(async () => {});
    fireEvent.click(screen.getByRole('button', { name: 'Show check-in QR' }));
    await act(async () => {});
    expect(document.querySelector('svg')).not.toBeNull();
    vi.mocked(api.post).mockRejectedValue(new ApiError(409, 'Expired'));
    await act(async () => {
        await vi.advanceTimersByTimeAsync(30000);
    });
    expect(api.post).toHaveBeenCalledTimes(2);
    expect(document.querySelector('svg')).toBeNull();
});
