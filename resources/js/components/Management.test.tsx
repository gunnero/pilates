import { beforeEach, expect, it, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter } from 'react-router-dom';
import App from '../App';
import { Locations } from './Locations';
import { Staff } from './Staff';
import { OrganizationSettings } from './OrganizationSettings';
import { api, ApiError, type Organization } from '../lib/api';
import i18n from '../lib/i18n';
vi.mock('../lib/api', async (original) => ({
    ...(await original<typeof import('../lib/api')>()),
    api: { get: vi.fn(), post: vi.fn(), patch: vi.fn() },
}));
const organization: Organization = {
    id: 'studio-a',
    name: 'Studio A',
    slug: 'studio-a',
    status: 'active',
    locale: 'mk',
    timezone: 'Europe/Skopje',
    currency: 'MKD',
    membership_id: 'access',
    location_scope: 'all',
    permissions: [
        'studio.access',
        'organizations.view',
        'organizations.manage',
        'locations.view',
        'locations.manage',
        'staff.view',
        'staff.manage',
        'permissions.view',
        'permissions.manage',
    ],
};
const meta = { current_page: 1, last_page: 1, per_page: 25, total: 1 };
beforeEach(async () => {
    vi.resetAllMocks();
    vi.mocked(api.get).mockResolvedValue({
        data: { effective: {}, overrides: {}, catalog: {} },
    });
    await i18n.changeLanguage('mk');
});
it('renders locations and server validation on create without discarding form input', async () => {
    vi.mocked(api.get).mockResolvedValue({
        data: [
            {
                id: 'loc-a',
                name: 'Centar',
                address: null,
                city: 'Bitola',
                country_code: 'MK',
                status: 'active',
            },
        ],
        meta,
    });
    vi.mocked(api.post).mockRejectedValue(
        new ApiError(422, 'Invalid', { name: ['Внесете име.'] }),
    );
    render(<Locations organization={organization} />);
    expect(
        await screen.findByRole('heading', { name: 'Centar' }),
    ).toBeInTheDocument();
    await userEvent.click(
        screen.getByRole('button', { name: 'Додај локација' }),
    );
    await userEvent.type(
        screen.getByLabelText('Име на локацијата'),
        'New branch',
    );
    await userEvent.click(
        screen.getByRole('button', { name: 'Зачувај промени' }),
    );
    expect(await screen.findByRole('alert')).toHaveTextContent('Внесете име.');
    expect(screen.getByLabelText('Име на локацијата')).toHaveValue(
        'New branch',
    );
});
it('hides creation for selected-location staff and does not request lists without view permission', async () => {
    vi.mocked(api.get).mockResolvedValue({ data: [], meta });
    const { unmount } = render(
        <Locations
            organization={{ ...organization, location_scope: 'selected' }}
        />,
    );
    expect(
        await screen.findByText('Сè уште нема достапни локации.'),
    ).toBeInTheDocument();
    expect(
        screen.queryByRole('button', { name: 'Додај локација' }),
    ).not.toBeInTheDocument();
    unmount();
    vi.clearAllMocks();
    render(<Locations organization={{ ...organization, permissions: [] }} />);
    expect(screen.getByRole('alert')).toHaveTextContent('Немате пристап');
    expect(api.get).not.toHaveBeenCalled();
});
it('shows staff permission summary only when serialized by the backend', async () => {
    vi.mocked(api.get).mockResolvedValue({
        data: [
            {
                id: 'member-a',
                user: { id: 'user-a', name: 'Jovana' },
                role: 'trainer',
                status: 'active',
                location_scope: 'selected',
                permissions: ['attendance.view'],
            },
            {
                id: 'member-b',
                user: { id: 'user-b', name: 'Marija' },
                role: 'staff',
                status: 'suspended',
                location_scope: 'all',
            },
        ],
        meta,
    });
    render(<Staff organization={organization} onChanged={vi.fn()} />);
    expect(
        await screen.findByRole('heading', { name: 'Jovana' }),
    ).toBeInTheDocument();
    expect(screen.getByText('Преглед на присуство')).toBeInTheDocument();
    expect(screen.queryByText('Преглед на плаќања')).not.toBeInTheDocument();
    expect(
        screen.getByText('Деталите за дозволите се ограничени.'),
    ).toBeInTheDocument();
});
it('persists a verified organization switch before rendering the destination', async () => {
    const second = { ...organization, id: 'studio-b', name: 'Studio B' };
    vi.mocked(api.get).mockImplementation(async (path) =>
        path === '/api/v1/me'
            ? {
                  data: {
                      id: 'user',
                      name: 'Ana',
                      active_organization_id: organization.id,
                      organizations: [organization, second],
                  },
              }
            : { data: path.includes('studio-b') ? second : organization },
    );
    vi.mocked(api.post).mockResolvedValue({ data: { id: 'studio-b' } });
    render(
        <MemoryRouter>
            <App />
        </MemoryRouter>,
    );
    await screen.findByRole('heading', { name: 'Простор за вашето студио' });
    await userEvent.selectOptions(
        screen.getByLabelText('Изберете студио'),
        'studio-b',
    );
    expect(api.post).toHaveBeenCalledWith(
        '/api/v1/organizations/studio-b/switch',
    );
    expect(
        await screen.findByText('Studio B', { selector: 'p.eyebrow' }),
    ).toBeInTheDocument();
});
it('does not render the destination when organization switching is denied', async () => {
    vi.mocked(api.get).mockImplementation(async (path) =>
        path === '/api/v1/me'
            ? {
                  data: {
                      id: 'user',
                      name: 'Ana',
                      active_organization_id: organization.id,
                      organizations: [
                          organization,
                          { id: 'foreign', name: 'Foreign' },
                      ],
                  },
              }
            : { data: organization },
    );
    vi.mocked(api.post).mockRejectedValue(new ApiError(404, 'Not found'));
    render(
        <MemoryRouter>
            <App />
        </MemoryRouter>,
    );
    await screen.findByRole('heading', { name: 'Простор за вашето студио' });
    await userEvent.selectOptions(
        screen.getByLabelText('Изберете студио'),
        'foreign',
    );
    expect(await screen.findByRole('alert')).toHaveTextContent(
        'Немате пристап',
    );
    expect(
        screen.queryByRole('heading', { name: 'Простор за вашето студио' }),
    ).not.toBeInTheDocument();
});
it('shows organization settings read-only without management permission', () => {
    render(
        <OrganizationSettings
            organization={{
                ...organization,
                permissions: ['organizations.view'],
            }}
            onChanged={vi.fn()}
        />,
    );
    expect(screen.getByLabelText('Име на студиото')).toBeDisabled();
    expect(
        screen.queryByRole('button', { name: 'Зачувај промени' }),
    ).not.toBeInTheDocument();
});

it('preserves undisclosed grants when a permission manager edits only status', async () => {
    vi.mocked(api.get).mockResolvedValue({
        data: [
            {
                id: 'member-a',
                user: { id: 'user-a', name: 'Jovana' },
                role: 'trainer',
                status: 'active',
                location_scope: 'all',
            },
        ],
        meta,
    });
    vi.mocked(api.patch).mockResolvedValue({});
    render(
        <Staff
            organization={{
                ...organization,
                permissions: organization.permissions.filter(
                    (permission) => permission !== 'permissions.view',
                ),
            }}
            onChanged={vi.fn()}
        />,
    );
    await userEvent.click(
        await screen.findByRole('button', { name: 'Уреди Jovana' }),
    );
    expect(screen.queryByRole('checkbox')).not.toBeInTheDocument();
    await userEvent.selectOptions(
        screen.getByRole('combobox', { name: 'Статус' }),
        'suspended',
    );
    await userEvent.click(
        screen.getByRole('button', { name: 'Зачувај промени' }),
    );
    expect(api.patch).toHaveBeenCalledWith(
        '/api/v1/organizations/studio-a/staff/member-a',
        { status: 'suspended' },
    );
});
