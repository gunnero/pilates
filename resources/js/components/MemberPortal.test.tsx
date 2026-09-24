import { beforeEach, expect, it, vi } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter } from 'react-router-dom';
import { MemberPortal } from './MemberPortal';
import { AuthForm } from './AuthForm';
import { api, ApiError } from '../lib/api';
import i18n from '../lib/i18n';
vi.mock('../lib/api', async (original) => ({
    ...(await original<typeof import('../lib/api')>()),
    api: { get: vi.fn(), post: vi.fn(), patch: vi.fn() },
}));
const meta = { current_page: 1, last_page: 1, per_page: 25, total: 1 };
const session = {
    id: 'session',
    group: { id: 'group', name: 'Morning Pilates' },
    location: { name: 'Centar' },
    trainer: { name: 'Trainer' },
    starts_at: '2026-10-01T08:00:00Z',
    ends_at: '2026-10-01T09:00:00Z',
    timezone: 'Europe/Skopje',
    status: 'scheduled',
    available_spots: 1,
    can_reserve: true,
    can_join_waiting: true,
    attendance_status: 'pending',
    reservation: null,
    waiting_entry: null,
};
function setup() {
    render(
        <MemoryRouter>
            <MemberPortal />
        </MemoryRouter>,
    );
    return userEvent.setup();
}
beforeEach(async () => {
    vi.resetAllMocks();
    await i18n.changeLanguage('en');
    vi.mocked(api.get).mockImplementation(async (path) => {
        if (path.startsWith('/api/v1/member/organizations?'))
            return {
                data: [
                    { id: 'one', name: 'First studio' },
                    { id: 'two', name: 'Second studio' },
                ],
                meta,
            };
        if (path.endsWith('/preferences'))
            return {
                data: {
                    reminders_enabled: true,
                    expiry_reminders_enabled: true,
                },
            };
        if (path.includes('/notifications'))
            return { data: [], meta, unread_count: 0 };
        if (path.endsWith('/profile'))
            return {
                data: {
                    id: 'me',
                    first_name: 'Ana',
                    last_name: 'Member',
                    email: 'contact@example.test',
                    phone: null,
                    preferred_locale: 'en',
                },
            };
        if (path.endsWith('/sessions/session')) return { data: session };
        if (path.includes('/sessions?')) return { data: [session], meta };
        if (path.endsWith('/groups?page=1'))
            return {
                data: [
                    {
                        id: 'group',
                        name: 'Morning Pilates',
                        description: 'Small classes',
                        location: { name: 'Centar' },
                        trainer: { name: 'Trainer' },
                        timezone: 'Europe/Skopje',
                        schedule: [
                            {
                                weekday: 1,
                                start_minute: 600,
                                end_minute: 660,
                                effective_from: '2026-09-01',
                                effective_until: null,
                            },
                        ],
                    },
                ],
                meta,
            };
        if (path.includes('/memberships'))
            return {
                data: [
                    {
                        id: 'membership',
                        plan_name: 'Monthly plan',
                        starts_on: '2026-09-01',
                        ends_on: '2026-10-01',
                        status: 'active',
                        agreed_price_minor: 150000,
                        net_paid_minor: 100000,
                        balance_minor: 50000,
                        currency: 'MKD',
                    },
                ],
                meta,
            };
        if (path.includes('/payments'))
            return {
                data: [
                    {
                        id: 'payment',
                        amount_minor: 100000,
                        currency: 'MKD',
                        paid_at: '2026-09-20T10:00:00Z',
                        status: 'reversed',
                        reversal: {
                            amount_minor: 100000,
                            created_at: '2026-09-21T10:00:00Z',
                        },
                    },
                ],
                meta,
            };
        if (path.includes('/attendance'))
            return {
                data: [
                    {
                        id: 'attendance',
                        status: 'present',
                        session: {
                            name: 'Previous Pilates',
                            starts_at: '2026-09-20T10:00:00Z',
                            timezone: 'Europe/Skopje',
                        },
                    },
                ],
                meta,
            };
        return { data: [], meta };
    });
});
it('renders member dashboard with own booking, membership and inbox', async () => {
    setup();
    expect(await screen.findByText('Morning Pilates')).toBeInTheDocument();
    expect(await screen.findByText('Monthly plan')).toBeInTheDocument();
    expect(await screen.findByText('Unread: 0')).toBeInTheDocument();
    expect(screen.queryByText('Staff')).not.toBeInTheDocument();
});
it('switches studios and clears the old workspace before resolving', async () => {
    const user = setup();
    await screen.findByText('Morning Pilates');
    let finish: () => void = () => {};
    vi.mocked(api.post).mockImplementation(
        () =>
            new Promise<void>((r) => {
                finish = r;
            }),
    );
    await user.selectOptions(
        screen.getByRole('combobox', { name: i18n.t('chooseStudio') }),
        'two',
    );
    expect(screen.queryByText('Morning Pilates')).not.toBeInTheDocument();
    finish();
    await waitFor(() =>
        expect(api.get).toHaveBeenCalledWith(
            expect.stringContaining('/organizations/two/sessions'),
            expect.any(AbortSignal),
        ),
    );
    expect(api.post).toHaveBeenCalledWith(
        '/api/v1/member/organizations/two/switch',
    );
});
it('discovers group schedule and reserves through session details', async () => {
    const user = setup();
    await screen.findByText('Morning Pilates');
    await user.click(
        screen.getAllByRole('button', { name: 'Available groups' })[0],
    );
    expect(await screen.findByText(/Monday 10:00/)).toBeInTheDocument();
    await user.click(
        screen.getByRole('button', { name: 'View sessions and availability' }),
    );
    await user.click(
        await screen.findByRole('button', { name: 'Session details' }),
    );
    await user.click(
        await screen.findByRole('button', { name: 'Reserve a spot' }),
    );
    expect(api.post).toHaveBeenCalledWith(
        '/api/v1/member/organizations/one/sessions/session/reservations',
        {},
    );
});
it('shows capacity conflict instead of pretending reservation succeeded', async () => {
    const user = setup();
    await user.click(
        await screen.findByRole('button', { name: 'Session details' }),
    );
    vi.mocked(api.post).mockRejectedValue(new ApiError(409, 'Session is full'));
    await user.click(
        await screen.findByRole('button', { name: 'Reserve a spot' }),
    );
    expect(await screen.findByRole('alert')).toHaveTextContent(
        'Session is full',
    );
});
it('joins a full session waiting list and can cancel own booking', async () => {
    const original = vi.mocked(api.get).getMockImplementation()!;
    vi.mocked(api.get).mockImplementation(async (path, signal) =>
        path.endsWith('/sessions/session')
            ? { data: { ...session, available_spots: 0 } }
            : original(path, signal),
    );
    const user = setup();
    await user.click(
        await screen.findByRole('button', { name: 'Session details' }),
    );
    await user.click(
        await screen.findByRole('button', { name: 'Join waiting list' }),
    );
    expect(api.post).toHaveBeenCalledWith(
        '/api/v1/member/organizations/one/sessions/session/reservations',
        { allow_waiting: true },
    );
    vi.mocked(api.get).mockImplementation(async (path, signal) =>
        path.endsWith('/sessions/session')
            ? {
                  data: {
                      ...session,
                      reservation: {
                          id: 'reservation',
                          status: 'active',
                          can_cancel: true,
                      },
                  },
              }
            : original(path, signal),
    );
    await user.click(
        await screen.findByRole('button', { name: 'Join waiting list' }),
    );
    await user.click(
        await screen.findByRole('button', { name: 'Cancel reservation' }),
    );
    expect(api.post).toHaveBeenCalledWith(
        '/api/v1/member/organizations/one/reservations/reservation/cancel',
        {},
    );
});
it('edits only safe profile fields and renders read-only finance and attendance', async () => {
    const user = setup();
    await screen.findByText('Morning Pilates');
    await user.click(screen.getByRole('button', { name: 'My profile' }));
    await user.clear(await screen.findByLabelText('First name'));
    await user.type(screen.getByLabelText('First name'), 'Ana Updated');
    await user.click(screen.getByRole('button', { name: i18n.t('save') }));
    await waitFor(() =>
        expect(api.patch).toHaveBeenCalledWith(
            '/api/v1/member/organizations/one/profile',
            {
                first_name: 'Ana Updated',
                last_name: 'Member',
                email: 'contact@example.test',
                phone: '',
                preferred_locale: 'en',
            },
        ),
    );
    await user.click(screen.getByRole('button', { name: 'Memberships' }));
    expect(await screen.findByText(/Balance:/)).toBeInTheDocument();
    await user.click(screen.getByRole('button', { name: 'Payments' }));
    expect(await screen.findByText(/Reversal:/)).toBeInTheDocument();
    await user.click(screen.getByRole('button', { name: 'Attendance' }));
    expect(await screen.findByText('Previous Pilates')).toBeInTheDocument();
    expect(
        screen.queryByRole('button', { name: 'Correct attendance' }),
    ).not.toBeInTheDocument();
});
it('renders MK and bounded empty-state pages', async () => {
    await i18n.changeLanguage('mk');
    const original = vi.mocked(api.get).getMockImplementation()!;
    vi.mocked(api.get).mockImplementation(async (path, signal) =>
        path.includes('/sessions?')
            ? { data: [], meta }
            : original(path, signal),
    );
    setup();
    expect(await screen.findByText('Моето студио')).toBeInTheDocument();
    expect(
        (await screen.findAllByText('Нема записи за приказ.')).length,
    ).toBeGreaterThan(0);
});
it('activates using fragment token and requires explicit sign in', async () => {
    render(
        <MemoryRouter
            initialEntries={[
                '/activate#token=secret&email=member%40example.test',
            ]}
        >
            <AuthForm mode="activate" member />
        </MemoryRouter>,
    );
    const user = userEvent.setup();
    await user.type(
        screen.getByLabelText('Password', { exact: true }),
        'Strong-password-123!',
    );
    await user.type(
        screen.getByLabelText('Confirm password'),
        'Strong-password-123!',
    );
    await user.click(screen.getByRole('button', { name: 'Save password' }));
    expect(api.post).toHaveBeenCalledWith('/auth/activate', {
        email: 'member@example.test',
        token: 'secret',
        password: 'Strong-password-123!',
        password_confirmation: 'Strong-password-123!',
    });
    expect(
        await screen.findByText('Account activated. You can now sign in.'),
    ).toBeInTheDocument();
    expect(
        screen.getByRole('link', { name: 'Back to sign in' }),
    ).toHaveAttribute('href', '/member/login');
});
