import { beforeEach, expect, it, vi } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter } from 'react-router-dom';
import { Reservations } from './Reservations';
import { PermanentSpots } from './PermanentSpots';
import { MemberBookings } from './MemberBookings';
import { api, ApiError, type Organization } from '../lib/api';
import i18n from '../lib/i18n';
vi.mock('../lib/api', async (original) => ({
    ...(await original<typeof import('../lib/api')>()),
    api: { get: vi.fn(), post: vi.fn(), patch: vi.fn() },
}));
const org: Organization = {
    id: 'org',
    name: 'Studio',
    slug: 'studio',
    status: 'active',
    locale: 'en',
    timezone: 'Europe/Skopje',
    currency: 'MKD',
    membership_id: 'staff',
    location_scope: 'selected',
    permissions: [
        'sessions.view',
        'groups.view',
        'reservations.view',
        'reservations.manage',
        'reservations.cancel',
        'waiting_list.view',
        'waiting_list.manage',
        'permanent_spots.view',
        'permanent_spots.manage',
    ],
};
const meta = { current_page: 1, last_page: 1, total: 0, per_page: 25 };
beforeEach(async () => {
    vi.resetAllMocks();
    await i18n.changeLanguage('en');
    vi.mocked(api.get).mockResolvedValue({ data: [], meta });
});
it.each(['reserved', 'waitlisted'])(
    'adds offline member and renders %s outcome',
    async (outcome) => {
        const user = userEvent.setup();
        vi.mocked(api.post).mockImplementation(async (path) =>
            path.endsWith('member-lookup')
                ? { data: [{ id: 'm', display_name: 'Offline Member' }] }
                : { data: { outcome } },
        );
        render(
            <Reservations
                organization={org}
                sessionId="s"
                onChanged={vi.fn()}
            />,
        );
        await user.type(screen.getByRole('textbox'), 'Offline');
        await user.click(screen.getByRole('button', { name: 'Search' }));
        await user.click(
            await screen.findByRole('button', {
                name: 'Reserve a place / join waiting list',
            }),
        );
        expect(await screen.findByRole('status')).toHaveTextContent(
            outcome === 'reserved'
                ? 'The place is reserved.'
                : 'Added to the waiting list.',
        );
        expect(api.post).toHaveBeenCalledWith(
            '/api/v1/organizations/org/sessions/s/reservations',
            { member_id: 'm', allow_waiting: true },
        );
    },
);
it('shows FIFO and confirms occurrence release before refreshing', async () => {
    const user = userEvent.setup();
    vi.spyOn(window, 'confirm').mockReturnValue(true);
    vi.mocked(api.post).mockResolvedValue({ data: {} });
    vi.mocked(api.get).mockImplementation(async (path) => ({
        data: path.includes('waiting-list')
            ? [
                  {
                      id: 'w1',
                      member: { id: 'b', display_name: 'Ana' },
                      status: 'waiting',
                  },
                  {
                      id: 'w2',
                      member: { id: 'c', display_name: 'Elena' },
                      status: 'waiting',
                  },
              ]
            : [
                  {
                      id: 'r',
                      member: { id: 'a', display_name: 'Offline' },
                      status: 'active',
                      source: 'permanent_spot',
                  },
              ],
        meta,
    }));
    const changed = vi.fn();
    render(
        <Reservations organization={org} sessionId="s" onChanged={changed} />,
    );
    expect(
        (await screen.findAllByRole('listitem')).map((n) => n.textContent),
    ).toEqual(['Ana Cancel', 'Elena Cancel']);
    await user.click(
        await screen.findByRole('button', {
            name: 'Release only this occurrence',
        }),
    );
    await waitFor(() => expect(changed).toHaveBeenCalled());
    expect(window.confirm).toHaveBeenCalledWith(
        'Release only this session? The permanent place remains active.',
    );
});
it('does not request private lists without permission', () => {
    render(
        <Reservations
            organization={{ ...org, permissions: [] }}
            sessionId="s"
            onChanged={vi.fn()}
        />,
    );
    expect(api.get).not.toHaveBeenCalled();
    expect(screen.queryByRole('textbox')).not.toBeInTheDocument();
});
it('retains lookup and reports business errors', async () => {
    const user = userEvent.setup();
    vi.mocked(api.post).mockRejectedValue(
        new ApiError(409, 'There are not enough places.'),
    );
    render(
        <Reservations organization={org} sessionId="s" onChanged={vi.fn()} />,
    );
    await user.type(screen.getByRole('textbox'), 'Offline');
    await user.click(screen.getByRole('button', { name: 'Search' }));
    expect(await screen.findByRole('alert')).toHaveTextContent(
        'There are not enough places.',
    );
    expect(screen.getByRole('textbox')).toHaveValue('Offline');
});
it('creates permanent place with explicit dates and surfaces capacity conflicts', async () => {
    const user = userEvent.setup();
    vi.mocked(api.post).mockImplementation(async (path) =>
        path.endsWith('member-lookup')
            ? { data: [{ id: 'm', display_name: 'Offline' }] }
            : { meta: { synchronization: { conflicts: 1 } } },
    );
    render(<PermanentSpots organization={org} groupId="g" />);
    await user.type(screen.getByRole('textbox'), 'Offline');
    await user.click(screen.getByRole('button', { name: 'Search' }));
    await screen.findByRole('combobox');
    await user.type(screen.getByLabelText('Effective from'), '2026-10-01');
    await user.click(
        screen.getByRole('button', { name: 'Add permanent place' }),
    );
    expect(await screen.findByRole('status')).toHaveTextContent(
        'some sessions have insufficient capacity',
    );
});
it('member profile exposes only granted related lists', async () => {
    render(
        <MemoryRouter>
            <MemberBookings
                organization={{
                    ...org,
                    permissions: ['sessions.view', 'reservations.view'],
                }}
                memberId="m"
            />
        </MemoryRouter>,
    );
    await waitFor(() => expect(api.get).toHaveBeenCalledTimes(1));
    expect(api.get).toHaveBeenCalledWith(
        '/api/v1/organizations/org/members/m/reservations?page=1',
    );
    expect(screen.queryByText('Permanent places')).not.toBeInTheDocument();
});
