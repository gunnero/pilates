import { beforeEach, expect, it, vi } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter } from 'react-router-dom';
import { Trainers } from './Trainers';
import { Staff } from './Staff';
import {
    api,
    ApiError,
    type Organization,
    type TrainerProfile,
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
    membership_id: 'actor',
    location_scope: 'all',
    permissions: [
        'trainers.view',
        'trainers.manage',
        'staff.view',
        'locations.view',
    ],
};
const trainer: TrainerProfile = {
    id: 'trainer',
    membership_id: 'staff',
    display_name: 'Jovana',
    display_name_override: null,
    bio: 'Teaching biography',
    status: 'active',
    staff_role: 'administrator',
    staff_status: 'active',
    location_scope: 'all',
    eligible_location_count: 1,
    eligible_locations: [{ id: 'location', name: 'Centar' }],
    assignable: true,
};
const meta = { current_page: 1, last_page: 1, per_page: 25, total: 1 };
const base = '/api/v1/organizations/org/trainers';
function show(org = organization, trainerId?: string) {
    return render(
        <MemoryRouter>
            <Trainers organization={org} trainerId={trainerId} />
        </MemoryRouter>,
    );
}
beforeEach(async () => {
    vi.resetAllMocks();
    await i18n.changeLanguage('en');
    vi.mocked(api.post).mockResolvedValue({ data: [trainer], meta });
    vi.mocked(api.get).mockImplementation(async (path) =>
        path.includes('/locations')
            ? {
                  data: [{ id: 'location', name: 'Centar', status: 'active' }],
                  meta,
              }
            : { data: trainer },
    );
});
it('renders trainers, eligibility and body-based name/status/location filters', async () => {
    show();
    await screen.findByRole('heading', { name: 'Jovana' });
    expect(
        screen.getByText('Eligible for future assignment'),
    ).toBeInTheDocument();
    await userEvent.type(screen.getByRole('searchbox'), 'Jov');
    await userEvent.selectOptions(
        screen.getByLabelText('Trainer status'),
        'active',
    );
    await userEvent.selectOptions(
        screen.getByLabelText('Eligible location'),
        'location',
    );
    await userEvent.click(screen.getByRole('button', { name: 'Search' }));
    expect(api.post).toHaveBeenLastCalledWith(
        `${base}/search`,
        { page: 1, search: 'Jov', status: 'active', location_id: 'location' },
        expect.any(AbortSignal),
    );
});
it('shows empty results and preserves filters during pagination', async () => {
    vi.mocked(api.post)
        .mockResolvedValueOnce({ data: [], meta })
        .mockResolvedValue({
            data: [trainer],
            meta: { ...meta, last_page: 2 },
        });
    show();
    await screen.findByText('No trainers match these filters.');
    await userEvent.type(screen.getByRole('searchbox'), 'Jov');
    await userEvent.click(screen.getByRole('button', { name: 'Search' }));
    await screen.findByRole('heading', { name: 'Jovana' });
    await userEvent.click(screen.getByRole('button', { name: 'Next' }));
    expect(api.post).toHaveBeenLastCalledWith(
        `${base}/search`,
        { page: 2, search: 'Jov', status: 'all' },
        expect.any(AbortSignal),
    );
});
it('renders detail and updates only operational fields', async () => {
    vi.mocked(api.patch).mockResolvedValue({
        data: {
            ...trainer,
            display_name: 'Jo',
            display_name_override: 'Jo',
            bio: null,
        },
    });
    show(organization, trainer.id);
    await screen.findByRole('heading', { name: 'Jovana' });
    expect(await screen.findByText('Centar')).toBeInTheDocument();
    await userEvent.type(
        screen.getByRole('textbox', { name: /Teaching display name/ }),
        'Jo',
    );
    await userEvent.clear(screen.getByRole('textbox', { name: /Short bio/ }));
    await userEvent.click(screen.getByRole('button', { name: 'Save changes' }));
    expect(api.patch).toHaveBeenCalledWith(`${base}/trainer`, {
        display_name: 'Jo',
        bio: null,
    });
    await screen.findByRole('heading', { name: 'Jo' });
    expect(
        screen.getByRole('link', { name: 'Manage staff access' }),
    ).toHaveAttribute('href', '/staff');
});
it('deactivates and reactivates the same profile, refreshing eligible locations', async () => {
    vi.mocked(api.patch)
        .mockResolvedValueOnce({
            data: {
                ...trainer,
                status: 'inactive',
                assignable: false,
                eligible_locations: [],
                eligible_location_count: 0,
            },
        })
        .mockResolvedValue({ data: trainer });
    show(organization, trainer.id);
    await userEvent.click(
        await screen.findByRole('button', { name: 'Deactivate trainer' }),
    );
    expect(api.patch).toHaveBeenLastCalledWith(`${base}/trainer/status`, {
        status: 'inactive',
    });
    await screen.findByText('Not currently eligible');
    await userEvent.click(
        screen.getByRole('button', { name: 'Activate trainer' }),
    );
    expect(api.patch).toHaveBeenLastCalledWith(`${base}/trainer/status`, {
        status: 'active',
    });
    await screen.findByText('Eligible for future assignment');
    await waitFor(() =>
        expect(
            vi
                .mocked(api.get)
                .mock.calls.filter(([path]) => path.includes('/locations')),
        ).toHaveLength(3),
    );
});
it('hides management for viewers and selected-location managers, and denies absent view permission', async () => {
    const first = show(
        { ...organization, permissions: ['trainers.view'] },
        trainer.id,
    );
    await screen.findByText('Teaching biography');
    expect(
        screen.queryByRole('button', { name: 'Deactivate trainer' }),
    ).not.toBeInTheDocument();
    first.unmount();
    const second = show(
        { ...organization, location_scope: 'selected' },
        trainer.id,
    );
    await screen.findByText('Teaching biography');
    expect(screen.queryByRole('textbox')).not.toBeInTheDocument();
    second.unmount();
    vi.clearAllMocks();
    show({ ...organization, permissions: [] });
    expect(screen.getByRole('alert')).toBeInTheDocument();
    expect(api.post).not.toHaveBeenCalled();
    expect(api.get).not.toHaveBeenCalled();
});
it('prevents reactivation for suspended staff and shows empty eligibility', async () => {
    vi.mocked(api.get).mockImplementation(async (path) =>
        path.includes('/locations')
            ? { data: [], meta }
            : {
                  data: {
                      ...trainer,
                      status: 'inactive',
                      staff_status: 'suspended',
                      assignable: false,
                  },
              },
    );
    show(organization, trainer.id);
    expect(
        await screen.findByRole('button', { name: 'Activate trainer' }),
    ).toBeDisabled();
    expect(
        await screen.findByText('No currently eligible locations'),
    ).toBeInTheDocument();
});
it('preserves edits on validation error and handles directory errors', async () => {
    vi.mocked(api.patch).mockRejectedValue(
        new ApiError(422, 'Invalid', { bio: ['Use a shorter biography.'] }),
    );
    const detail = show(organization, trainer.id);
    await screen.findByRole('heading', { name: 'Jovana' });
    await userEvent.click(screen.getByRole('button', { name: 'Save changes' }));
    expect(await screen.findByRole('alert')).toHaveTextContent(
        'Use a shorter biography.',
    );
    expect(screen.getByRole('textbox', { name: /Short bio/ })).toHaveValue(
        'Teaching biography',
    );
    detail.unmount();
    vi.mocked(api.post).mockRejectedValue(new ApiError(403, 'Access denied'));
    show();
    expect(await screen.findByRole('alert')).toHaveTextContent('Access denied');
});
it('enables an existing active staff member and refreshes its trainer link', async () => {
    const staff = {
        id: 'staff',
        user: { id: 'user', name: 'Jovana' },
        role: 'administrator',
        status: 'active',
        location_scope: 'all',
        trainer_profile: null,
    };
    vi.mocked(api.get)
        .mockResolvedValueOnce({ data: [staff], meta })
        .mockResolvedValue({
            data: [
                {
                    ...staff,
                    trainer_profile: { id: 'trainer', status: 'active' },
                },
            ],
            meta,
        });
    vi.mocked(api.post).mockResolvedValue({ data: trainer });
    render(
        <MemoryRouter>
            <Staff organization={organization} onChanged={vi.fn()} />
        </MemoryRouter>,
    );
    await userEvent.click(
        await screen.findByRole('button', { name: 'Enable as trainer Jovana' }),
    );
    expect(api.post).toHaveBeenCalledWith(base, { membership_id: 'staff' });
    expect(
        await screen.findByRole('link', { name: 'Open trainer profile' }),
    ).toHaveAttribute('href', '/trainers/trainer');
    expect(
        screen.queryByRole('button', { name: 'Enable as trainer Jovana' }),
    ).not.toBeInTheDocument();
});
it('shows enablement failure and hides enablement without management permission', async () => {
    vi.mocked(api.get).mockResolvedValue({
        data: [
            {
                id: 'staff',
                user: { id: 'user', name: 'Jovana' },
                role: 'staff',
                status: 'active',
                location_scope: 'all',
                trainer_profile: null,
            },
        ],
        meta,
    });
    vi.mocked(api.post).mockRejectedValue(new ApiError(409, 'Already enabled'));
    const first = render(
        <MemoryRouter>
            <Staff organization={organization} onChanged={vi.fn()} />
        </MemoryRouter>,
    );
    await userEvent.click(
        await screen.findByRole('button', { name: 'Enable as trainer Jovana' }),
    );
    expect(await screen.findByRole('alert')).toHaveTextContent(
        'Already enabled',
    );
    first.unmount();
    render(
        <MemoryRouter>
            <Staff
                organization={{
                    ...organization,
                    permissions: ['staff.view', 'trainers.view'],
                }}
                onChanged={vi.fn()}
            />
        </MemoryRouter>,
    );
    await screen.findByRole('heading', { name: 'Jovana' });
    expect(
        screen.queryByRole('button', { name: 'Enable as trainer Jovana' }),
    ).not.toBeInTheDocument();
});
