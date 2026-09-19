import { beforeEach, expect, it, vi } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter } from 'react-router-dom';
import { Sessions, SessionForm } from './Sessions';
import {
    api,
    ApiError,
    type Organization,
    type TrainingSession,
    type TrainingGroup,
} from '../lib/api';
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
    locale: 'mk',
    timezone: 'Europe/Skopje',
    currency: 'MKD',
    membership_id: 'staff',
    location_scope: 'all',
    permissions: [
        'sessions.view',
        'sessions.manage',
        'sessions.cancel',
        'groups.view',
        'locations.view',
        'trainers.view',
    ],
};
const session: TrainingSession = {
    id: 'session',
    group: { id: 'group', name: 'Pilates' },
    location: { id: 'loc', name: 'Centar' },
    trainer: { id: 'trainer', display_name: 'Jovana' },
    local_date: '2026-09-21',
    start_time: '18:00',
    end_time: '19:00',
    starts_at: '2026-09-21T16:00:00Z',
    ends_at: '2026-09-21T17:00:00Z',
    timezone: 'Europe/Skopje',
    capacity: 12,
    status: 'scheduled',
    source_type: 'recurring',
    is_overridden: false,
    cancellation_reason: null,
    cancellation_kind: null,
    operational_issues: [],
    editable: true,
};
const group: TrainingGroup = {
    id: 'group',
    name: 'Pilates',
    capacity: 12,
    status: 'active',
    location: { id: 'loc', name: 'Centar', status: 'active' },
    primary_trainer: { id: 'trainer', display_name: 'Jovana' },
    timezone: 'Europe/Skopje',
    eligible_for_scheduling: true,
    operational_issues: [],
};
const meta = { current_page: 1, last_page: 1, total: 1, per_page: 25 };
const base = '/api/v1/organizations/org/sessions';
function show(id?: string, organization = org) {
    return render(
        <MemoryRouter>
            <Sessions organization={organization} sessionId={id} />
        </MemoryRouter>,
    );
}
beforeEach(async () => {
    vi.resetAllMocks();
    await i18n.changeLanguage('en');
    vi.mocked(api.get).mockImplementation(async (path) =>
        path.includes('/locations')
            ? { data: [{ id: 'loc', name: 'Centar', status: 'active' }], meta }
            : path.includes('/sessions?')
              ? { data: [session], meta }
              : { data: session },
    );
    vi.mocked(api.post).mockImplementation(async (path) =>
        path.includes('/groups/search')
            ? { data: [group], meta }
            : path.includes('/trainers/search')
              ? {
                    data: [
                        {
                            id: 'trainer',
                            display_name: 'Jovana',
                            status: 'active',
                        },
                    ],
                    meta,
                }
              : { data: session },
    );
});
it('renders agenda, snapshots and real timezone without occupancy', async () => {
    show();
    expect(
        await screen.findByText('18:00–19:00 · Pilates'),
    ).toBeInTheDocument();
    expect(screen.getByText(/Capacity: 12/)).toBeInTheDocument();
    expect(screen.getByText(/Local time: Europe\/Skopje/)).toBeInTheDocument();
    expect(
        screen.queryByText(/available spots|reserved/i),
    ).not.toBeInTheDocument();
});
it('navigates dates and applies location trainer group status filters', async () => {
    const user = userEvent.setup();
    show();
    await screen.findByText('18:00–19:00 · Pilates');
    await user.click(screen.getByText('Next week'));
    await user.click(screen.getByText('Previous week'));
    await user.selectOptions(
        screen.getByRole('combobox', { name: 'Location' }),
        'loc',
    );
    await user.selectOptions(
        screen.getByRole('combobox', { name: 'Primary trainer' }),
        'trainer',
    );
    await user.selectOptions(
        screen.getByRole('combobox', { name: 'Group' }),
        'group',
    );
    await user.selectOptions(
        screen.getByRole('combobox', { name: 'Status' }),
        'cancelled',
    );
    await waitFor(() =>
        expect(api.get).toHaveBeenLastCalledWith(
            expect.stringContaining('status=cancelled'),
            expect.any(AbortSignal),
        ),
    );
    expect(vi.mocked(api.get).mock.calls.at(-1)?.[0]).toContain(
        'trainer_id=trainer',
    );
});
it('shows empty and failed calendar states', async () => {
    vi.mocked(api.get).mockResolvedValue({ data: [], meta });
    const v = show(undefined, { ...org, permissions: ['sessions.view'] });
    expect(
        await screen.findByText('No sessions in this period.'),
    ).toBeInTheDocument();
    v.unmount();
    vi.mocked(api.get).mockRejectedValue(
        new ApiError(422, 'Choose a smaller range'),
    );
    show(undefined, { ...org, permissions: ['sessions.view'] });
    expect(await screen.findByRole('alert')).toHaveTextContent(
        'Choose a smaller range',
    );
});
it('renders detail with permission gated actions and operational invalidity', async () => {
    vi.mocked(api.get).mockResolvedValue({
        data: { ...session, operational_issues: ['trainer_unavailable'] },
    });
    show('session', { ...org, permissions: ['sessions.view'] });
    expect(
        await screen.findByText('18:00–19:00 · Pilates'),
    ).toBeInTheDocument();
    expect(
        screen.getByText(/Assigned trainer is currently unavailable/),
    ).toBeInTheDocument();
    expect(
        screen.queryByRole('button', { name: 'Edit only this occurrence' }),
    ).not.toBeInTheDocument();
    expect(
        screen.queryByRole('button', { name: 'Cancel occurrence' }),
    ).not.toBeInTheDocument();
});
it('creates a manual session with group defaults', async () => {
    const saved = vi.fn();
    const user = userEvent.setup();
    render(
        <SessionForm organization={org} onSaved={saved} onCancel={() => {}} />,
    );
    await screen.findByRole('option', { name: 'Pilates' });
    await user.selectOptions(
        screen.getByRole('combobox', { name: 'Group' }),
        'group',
    );
    await user.type(screen.getByLabelText('Session date'), '2026-09-22');
    await user.click(screen.getByRole('button', { name: 'Save changes' }));
    await waitFor(() => expect(saved).toHaveBeenCalled());
    expect(api.post).toHaveBeenCalledWith(
        base,
        expect.objectContaining({
            group_id: 'group',
            capacity: 12,
            trainer_id: 'trainer',
            location_id: 'loc',
            local_date: '2026-09-22',
        }),
    );
});
it('edits only the occurrence and retains inputs on conflict', async () => {
    vi.mocked(api.patch).mockRejectedValueOnce(
        new ApiError(409, 'Trainer conflict'),
    );
    vi.mocked(api.patch).mockResolvedValueOnce({
        data: { ...session, capacity: 9, is_overridden: true },
    });
    const user = userEvent.setup();
    show('session');
    await user.click(
        await screen.findByRole('button', {
            name: 'Edit only this occurrence',
        }),
    );
    const cap = screen.getByLabelText('Capacity (1–100)');
    await user.clear(cap);
    await user.type(cap, '9');
    await user.click(screen.getByRole('button', { name: 'Save changes' }));
    expect(await screen.findByRole('alert')).toHaveTextContent(
        'Trainer conflict',
    );
    expect(cap).toHaveValue(9);
    await user.click(screen.getByRole('button', { name: 'Save changes' }));
    await waitFor(() =>
        expect(
            screen.queryByLabelText('Capacity (1–100)'),
        ).not.toBeInTheDocument(),
    );
    expect(api.patch).toHaveBeenCalledWith(
        `${base}/session`,
        expect.objectContaining({ capacity: 9 }),
    );
    expect(vi.mocked(api.patch).mock.calls[0][1]).not.toHaveProperty(
        'group_id',
    );
});
it('confirms one occurrence cancellation and retains cancelled history', async () => {
    vi.mocked(api.post).mockResolvedValue({
        data: {
            ...session,
            status: 'cancelled',
            editable: false,
            cancellation_reason: 'Holiday',
        },
    });
    const user = userEvent.setup();
    show('session');
    await user.click(
        await screen.findByRole('button', { name: 'Cancel occurrence' }),
    );
    expect(api.post).not.toHaveBeenCalled();
    await user.type(screen.getByLabelText('Reason (optional)'), 'Holiday');
    await user.click(
        screen.getByRole('button', { name: 'Confirm cancellation' }),
    );
    expect(
        await screen.findByText('Cancelled', { exact: true }),
    ).toBeInTheDocument();
    expect(
        screen.queryByRole('button', { name: 'Cancel occurrence' }),
    ).not.toBeInTheDocument();
    expect(api.post).toHaveBeenCalledWith(`${base}/session/cancel`, {
        reason: 'Holiday',
    });
});
it('denies missing view access without querying sessions', () => {
    show(undefined, { ...org, permissions: ['sessions.manage'] });
    expect(screen.getByRole('alert')).toBeInTheDocument();
    expect(api.get).not.toHaveBeenCalled();
});
it('materializes selected group and reports skipped work', async () => {
    const user = userEvent.setup();
    show();
    await screen.findByRole('option', { name: 'Pilates' });
    await user.selectOptions(
        screen.getByRole('combobox', { name: 'Group' }),
        'group',
    );
    vi.mocked(api.post).mockResolvedValue({
        data: {
            created: 7,
            existing: 1,
            invalid: 0,
            dst: 1,
            conflict: 0,
            limit: 0,
        },
    });
    await user.click(
        screen.getByRole('button', { name: 'Prepare next 8 weeks' }),
    );
    expect(
        await screen.findByText(
            'Created: 7. Already prepared: 1. Need attention: 1.',
        ),
    ).toBeInTheDocument();
});
