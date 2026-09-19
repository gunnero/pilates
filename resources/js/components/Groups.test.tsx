import { beforeEach, expect, it, vi } from 'vitest';
import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter } from 'react-router-dom';
import { Groups } from './Groups';
import { GroupSchedule } from './GroupSchedule';
import {
    api,
    ApiError,
    type Organization,
    type TrainingGroup,
    type ScheduleRule,
} from '../lib/api';
import i18n from '../lib/i18n';
vi.mock('../lib/api', async (original) => ({
    ...(await original<typeof import('../lib/api')>()),
    api: { get: vi.fn(), post: vi.fn(), patch: vi.fn() },
}));
const organization: Organization = {
    id: 'org',
    name: 'Fixture',
    slug: 'fixture',
    status: 'active',
    locale: 'mk',
    timezone: 'Europe/Skopje',
    currency: 'MKD',
    membership_id: 'staff',
    location_scope: 'all',
    permissions: [
        'groups.view',
        'groups.manage',
        'schedule.view',
        'schedule.manage',
        'locations.view',
        'trainers.view',
    ],
};
const rule: ScheduleRule = {
    id: 'rule',
    weekday: 1,
    start_time: '18:00',
    end_time: '19:00',
    effective_from: '2026-09-21',
    effective_until: null,
    canceled: false,
};
const group: TrainingGroup = {
    id: 'group',
    name: 'Pilates Beginners',
    description: 'Fixture description',
    capacity: 12,
    status: 'active',
    location: { id: 'loc', name: 'Centar', status: 'active' },
    primary_trainer: { id: 'trainer', display_name: 'Jovana' },
    timezone: organization.timezone,
    eligible_for_scheduling: true,
    operational_issues: [],
    schedule_summary: [rule],
    schedule_rule_count: 1,
};
const meta = {
    current_page: 1,
    last_page: 1,
    per_page: 25,
    total: 1,
    today: '2026-09-19',
    timezone: 'Europe/Skopje',
};
const base = '/api/v1/organizations/org/groups';
function show(org = organization, id?: string) {
    return render(
        <MemoryRouter>
            <Groups organization={org} groupId={id} />
        </MemoryRouter>,
    );
}
beforeEach(async () => {
    vi.resetAllMocks();
    await i18n.changeLanguage('en');
    vi.mocked(api.get).mockImplementation(async (path) =>
        path.includes('/locations')
            ? {
                  data: [
                      { id: 'loc', name: 'Centar', status: 'active' },
                      { id: 'other', name: 'Other', status: 'active' },
                  ],
                  meta,
              }
            : path.includes('/schedule')
              ? { data: [rule], meta }
              : { data: group },
    );
    vi.mocked(api.post).mockImplementation(async (path) =>
        path.includes('/trainers')
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
            : path.endsWith('/search')
              ? { data: [group], meta }
              : { data: group },
    );
});
it('renders configured capacity and sends group filters in the body', async () => {
    show();
    await screen.findByRole('heading', { name: 'Pilates Beginners' });
    expect(screen.getByText(/Capacity: 12/)).toBeInTheDocument();
    expect(screen.queryByText(/occupied/)).not.toBeInTheDocument();
    await userEvent.type(screen.getByRole('searchbox'), 'Pilates');
    await userEvent.selectOptions(screen.getByLabelText('Status'), 'inactive');
    await userEvent.selectOptions(screen.getByLabelText('Location'), 'loc');
    await userEvent.selectOptions(
        screen.getByLabelText('Primary trainer'),
        'trainer',
    );
    await userEvent.click(screen.getByRole('button', { name: 'Search' }));
    expect(api.post).toHaveBeenLastCalledWith(
        `${base}/search`,
        {
            page: 1,
            search: 'Pilates',
            status: 'inactive',
            location_id: 'loc',
            trainer_id: 'trainer',
        },
        expect.any(AbortSignal),
    );
});
it('creates a group with an eligible trainer; changing location clears the prior assignment', async () => {
    show();
    await userEvent.click(screen.getByRole('button', { name: 'Add group' }));
    const form = screen
        .getByRole('heading', { name: 'Add group' })
        .closest('form')!;
    await userEvent.type(
        within(form).getByLabelText('Group name'),
        'New Pilates',
    );
    await userEvent.selectOptions(
        within(form).getByLabelText('Location'),
        'loc',
    );
    await screen.findAllByRole('option', { name: 'Jovana' });
    await userEvent.selectOptions(
        within(form).getByLabelText('Primary trainer'),
        'trainer',
    );
    await userEvent.selectOptions(
        within(form).getByLabelText('Location'),
        'other',
    );
    expect(within(form).getByLabelText('Primary trainer')).toHaveValue('');
    await waitFor(() =>
        expect(api.post).toHaveBeenCalledWith(
            '/api/v1/organizations/org/trainers/search',
            { page: 1, per_page: 100, status: 'active', location_id: 'other' },
            expect.any(AbortSignal),
        ),
    );
    await userEvent.click(
        within(form).getByRole('button', { name: 'Save changes' }),
    );
    expect(api.post).toHaveBeenCalledWith(base, {
        name: 'New Pilates',
        description: null,
        capacity: 12,
        location_id: 'other',
        primary_trainer_id: null,
    });
});
it('shows unavailable assignment and keeps group and schedule history visible', async () => {
    vi.mocked(api.get).mockImplementation(async (path) =>
        path.includes('/schedule')
            ? { data: [rule], meta }
            : {
                  data: {
                      ...group,
                      operational_issues: [
                          'trainer_unavailable',
                          'location_inactive',
                      ],
                      eligible_for_scheduling: false,
                  },
              },
    );
    show(organization, group.id);
    expect(
        await screen.findByText(
            'Assigned trainer is currently unavailable at this location.',
        ),
    ).toBeInTheDocument();
    expect(
        screen.getByText('Location is inactive. Scheduling is unavailable.'),
    ).toBeInTheDocument();
    expect(
        await screen.findByText('Local time: Europe/Skopje'),
    ).toBeInTheDocument();
    expect(
        await screen.findByRole('heading', { name: 'Monday · 18:00–19:00' }),
    ).toBeInTheDocument();
});
it('enforces read-only UI and archived restoration', async () => {
    const first = show(
        { ...organization, permissions: ['groups.view'] },
        group.id,
    );
    await screen.findByRole('heading', { name: group.name });
    expect(
        screen.queryByRole('button', { name: 'Edit group' }),
    ).not.toBeInTheDocument();
    expect(
        screen.getByText('Schedule access is restricted.'),
    ).toBeInTheDocument();
    first.unmount();
    vi.mocked(api.get).mockResolvedValue({
        data: { ...group, status: 'archived' },
    });
    vi.mocked(api.patch).mockResolvedValue({
        data: { ...group, status: 'inactive' },
    });
    show(
        { ...organization, permissions: ['groups.view', 'groups.manage'] },
        group.id,
    );
    await screen.findByLabelText('Change group status');
    expect(
        screen.queryByRole('option', { name: /^Active$/ }),
    ).not.toBeInTheDocument();
    await userEvent.selectOptions(
        screen.getByLabelText('Change group status'),
        'inactive',
    );
    await userEvent.click(screen.getByRole('button', { name: 'Apply status' }));
    expect(api.patch).toHaveBeenCalledWith(`${base}/group/status`, {
        status: 'inactive',
    });
    expect(
        await screen.findByRole('button', { name: 'Edit group' }),
    ).toBeInTheDocument();
});
it('denies requests without group access and handles empty/error lists', async () => {
    const first = show({ ...organization, permissions: [] });
    expect(screen.getByRole('alert')).toBeInTheDocument();
    expect(api.post).not.toHaveBeenCalled();
    first.unmount();
    vi.mocked(api.post).mockResolvedValue({ data: [], meta });
    const second = show({ ...organization, permissions: ['groups.view'] });
    await screen.findByText('No groups match these filters.');
    second.unmount();
    vi.mocked(api.post).mockRejectedValue(new ApiError(403, 'Access denied'));
    show({ ...organization, permissions: ['groups.view'] });
    expect(await screen.findByRole('alert')).toHaveTextContent('Access denied');
});
it('adds weekly wall-clock times and keeps input on conflict', async () => {
    vi.mocked(api.post).mockRejectedValue(
        new ApiError(409, 'Trainer schedule conflict'),
    );
    render(
        <GroupSchedule
            organization={organization}
            groupId="group"
            archived={false}
            onChanged={vi.fn()}
        />,
    );
    await userEvent.click(
        await screen.findByRole('button', { name: 'Add weekly time' }),
    );
    await userEvent.selectOptions(screen.getByLabelText('Weekday'), '3');
    await userEvent.click(screen.getByRole('button', { name: 'Save changes' }));
    expect(api.post).toHaveBeenCalledWith(`${base}/group/schedule`, {
        weekday: 3,
        start_time: '18:00',
        end_time: '19:00',
        effective_from: '2026-09-19',
        effective_until: null,
    });
    expect(await screen.findByRole('alert')).toHaveTextContent(
        'Trainer schedule conflict',
    );
    expect(screen.getByLabelText('Weekday')).toHaveValue('3');
});
it('replaces a schedule from a future date without editing the original rule', async () => {
    vi.mocked(api.post).mockResolvedValue({ data: rule });
    const changed = vi.fn();
    render(
        <GroupSchedule
            organization={organization}
            groupId="group"
            archived={false}
            onChanged={changed}
        />,
    );
    await userEvent.click(
        await screen.findByRole('button', {
            name: 'Change from a future date',
        }),
    );
    expect(
        screen.getByText(/existing time remains unchanged/),
    ).toBeInTheDocument();
    await userEvent.type(screen.getByLabelText('Effective from'), '2026-11-01');
    await userEvent.clear(screen.getByLabelText('Start time'));
    await userEvent.type(screen.getByLabelText('Start time'), '19:00');
    await userEvent.clear(screen.getByLabelText('End time'));
    await userEvent.type(screen.getByLabelText('End time'), '20:00');
    await userEvent.click(screen.getByRole('button', { name: 'Save changes' }));
    expect(api.post).toHaveBeenCalledWith(
        `${base}/group/schedule/rule/replace`,
        {
            weekday: 1,
            start_time: '19:00',
            end_time: '20:00',
            effective_from: '2026-11-01',
            effective_until: null,
        },
    );
    expect(changed).toHaveBeenCalled();
});
it('closes a period and cancels only future rules', async () => {
    vi.mocked(api.patch).mockResolvedValue({ data: null });
    vi.mocked(api.post).mockResolvedValue({ data: null });
    render(
        <GroupSchedule
            organization={organization}
            groupId="group"
            archived={false}
            onChanged={vi.fn()}
        />,
    );
    await userEvent.click(
        await screen.findByRole('button', { name: 'Set last effective date' }),
    );
    await userEvent.type(
        screen.getByLabelText('Effective through (inclusive)'),
        '2026-10-31',
    );
    await userEvent.click(screen.getByRole('button', { name: 'Save changes' }));
    expect(api.patch).toHaveBeenCalledWith(
        `${base}/group/schedule/rule/close`,
        { effective_until: '2026-10-31' },
    );
    await userEvent.click(
        await screen.findByRole('button', {
            name: 'Cancel future weekly time',
        }),
    );
    expect(api.post).toHaveBeenCalledWith(`${base}/group/schedule/rule/cancel`);
});
it('hides schedule mutations for viewers and archived groups', async () => {
    const first = render(
        <GroupSchedule
            organization={{ ...organization, permissions: ['schedule.view'] }}
            groupId="group"
            archived={false}
            onChanged={vi.fn()}
        />,
    );
    await screen.findByRole('heading', { name: 'Monday · 18:00–19:00' });
    expect(
        screen.queryByRole('button', { name: 'Add weekly time' }),
    ).not.toBeInTheDocument();
    first.unmount();
    render(
        <GroupSchedule
            organization={organization}
            groupId="group"
            archived={true}
            onChanged={vi.fn()}
        />,
    );
    await screen.findByRole('heading', { name: 'Monday · 18:00–19:00' });
    expect(
        screen.queryByRole('button', { name: 'Change from a future date' }),
    ).not.toBeInTheDocument();
});
