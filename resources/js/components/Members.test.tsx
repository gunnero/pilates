import { beforeEach, expect, it, vi } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { Members } from './Members';
import {
    api,
    ApiError,
    type Organization,
    type StudioMember,
} from '../lib/api';
import i18n from '../lib/i18n';
vi.mock('./MemberInvitations', () => ({ MemberInvitations: () => null }));
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
        'members.view',
        'members.create',
        'members.update',
        'members.status.manage',
        'members.notes.view',
        'members.notes.manage',
    ],
};
const client: StudioMember = {
    id: 'client',
    first_name: 'Тест',
    last_name: 'Клиент',
    phone: null,
    email: null,
    status: 'active',
    digital_access_mode: 'offline',
    preferred_locale: 'mk',
    preferred_location: null,
    notes: 'Оперативна тест забелешка',
    created_at: '2026-09-19T00:00:00Z',
    updated_at: '2026-09-19T00:00:00Z',
};
const meta = { current_page: 1, last_page: 1, per_page: 25, total: 1 };
beforeEach(async () => {
    vi.resetAllMocks();
    await i18n.changeLanguage('mk');
    vi.mocked(api.post).mockResolvedValue({ data: [client], meta });
    vi.mocked(api.get).mockResolvedValue({ data: client });
});
it('renders clients and sends bounded search/filter state in a body, never the URL', async () => {
    render(<Members organization={organization} />);
    expect(
        await screen.findByRole('heading', { name: 'Тест Клиент' }),
    ).toBeInTheDocument();
    await userEvent.type(screen.getByRole('searchbox'), 'Тест');
    await userEvent.selectOptions(
        screen.getByRole('combobox', { name: 'Статус' }),
        'inactive',
    );
    await userEvent.selectOptions(
        screen.getByRole('combobox', { name: 'Начин на користење' }),
        'offline',
    );
    await userEvent.click(screen.getByRole('button', { name: 'Пребарај' }));
    expect(api.post).toHaveBeenLastCalledWith(
        '/api/v1/organizations/org/members/search',
        {
            page: 1,
            search: 'Тест',
            status: 'inactive',
            digital_access_mode: 'offline',
        },
        expect.any(AbortSignal),
    );
});
it('shows a useful empty state and pagination preserves filters', async () => {
    vi.mocked(api.post)
        .mockResolvedValueOnce({ data: [], meta: { ...meta, total: 0 } })
        .mockResolvedValue({
            data: [client],
            meta: { ...meta, last_page: 2, total: 26 },
        });
    render(<Members organization={organization} />);
    expect(
        await screen.findByText('Нема клиенти што одговараат на филтрите.'),
    ).toBeInTheDocument();
    await userEvent.type(screen.getByRole('searchbox'), 'Тест');
    await userEvent.click(screen.getByRole('button', { name: 'Пребарај' }));
    await screen.findByRole('heading', { name: 'Тест Клиент' });
    await userEvent.click(screen.getByRole('button', { name: 'Следна' }));
    expect(api.post).toHaveBeenLastCalledWith(
        '/api/v1/organizations/org/members/search',
        { page: 2, search: 'Тест' },
        expect.any(AbortSignal),
    );
});
it('creates an offline client with no phone/email and opens the resulting profile', async () => {
    vi.mocked(api.post).mockImplementation(async (path) =>
        path.endsWith('/search')
            ? { data: [], meta }
            : { data: client, meta: { duplicate_warning: true } },
    );
    render(<Members organization={organization} />);
    await userEvent.click(screen.getByRole('button', { name: 'Додај клиент' }));
    await userEvent.type(screen.getByLabelText('Име', { exact: true }), 'Тест');
    await userEvent.type(screen.getByLabelText('Презиме'), 'Клиент');
    expect(screen.getByLabelText('Е-пошта')).not.toBeRequired();
    await userEvent.click(
        screen.getByRole('button', { name: 'Зачувај промени' }),
    );
    expect(api.post).toHaveBeenCalledWith(
        '/api/v1/organizations/org/members',
        expect.objectContaining({
            first_name: 'Тест',
            last_name: 'Клиент',
            phone: null,
            email: null,
            digital_access_mode: 'offline',
        }),
    );
    expect(
        await screen.findByRole('heading', { name: 'Профил на клиент' }),
    ).toBeInTheDocument();
    expect(screen.getByText(/Постои друг клиент/)).toBeInTheDocument();
});
it('retains form values and displays server validation failures', async () => {
    vi.mocked(api.post).mockImplementation(async (path) => {
        if (path.endsWith('/search')) return { data: [], meta };
        throw new ApiError(422, 'Invalid', {
            phone: ['Внесете меѓународен број.'],
        });
    });
    render(<Members organization={organization} />);
    await userEvent.click(screen.getByRole('button', { name: 'Додај клиент' }));
    await userEvent.type(screen.getByLabelText('Име', { exact: true }), 'Тест');
    await userEvent.type(screen.getByLabelText('Презиме'), 'Клиент');
    await userEvent.type(screen.getByLabelText('Телефон'), '070123456');
    await userEvent.click(
        screen.getByRole('button', { name: 'Зачувај промени' }),
    );
    expect(await screen.findByRole('alert')).toHaveTextContent(
        'Внесете меѓународен број.',
    );
    expect(screen.getByLabelText('Телефон')).toHaveValue('070123456');
});
it('renders profile notes only when the backend includes them and preserves undisclosed notes on edit', async () => {
    const withoutNotes = { ...client };
    delete withoutNotes.notes;
    vi.mocked(api.get).mockResolvedValue({ data: withoutNotes });
    vi.mocked(api.patch).mockResolvedValue({ data: withoutNotes });
    render(
        <Members
            organization={{
                ...organization,
                permissions: organization.permissions.filter(
                    (permission) => permission !== 'members.notes.view',
                ),
            }}
        />,
    );
    await userEvent.click(
        await screen.findByRole('button', {
            name: 'Отвори профил Тест Клиент',
        }),
    );
    expect(
        screen.queryByText('Оперативна тест забелешка'),
    ).not.toBeInTheDocument();
    await userEvent.click(screen.getByRole('button', { name: 'Уреди клиент' }));
    expect(
        screen.queryByRole('textbox', { name: 'Забелешка' }),
    ).not.toBeInTheDocument();
    await userEvent.click(
        screen.getByRole('button', { name: 'Зачувај промени' }),
    );
    expect(vi.mocked(api.patch).mock.calls[0]?.[1]).not.toHaveProperty('notes');
});
it('shows authorized notes and requires inactive restoration for archived profiles', async () => {
    vi.mocked(api.get).mockResolvedValue({
        data: { ...client, status: 'archived' },
    });
    vi.mocked(api.patch).mockResolvedValue({
        data: { ...client, status: 'inactive' },
    });
    render(<Members organization={organization} />);
    await userEvent.click(
        await screen.findByRole('button', {
            name: 'Отвори профил Тест Клиент',
        }),
    );
    expect(screen.getByText('Оперативна тест забелешка')).toBeInTheDocument();
    expect(
        screen.queryByRole('button', { name: 'Уреди клиент' }),
    ).not.toBeInTheDocument();
    expect(
        screen.queryByRole('option', { name: 'Активен' }),
    ).not.toBeInTheDocument();
    await userEvent.selectOptions(
        screen.getByRole('combobox', { name: 'Промени статус на клиент' }),
        'inactive',
    );
    await userEvent.click(
        screen.getByRole('button', { name: 'Примени статус' }),
    );
    expect(api.patch).toHaveBeenCalledWith(
        '/api/v1/organizations/org/members/client/status',
        { status: 'inactive' },
    );
    expect(
        await screen.findByRole('button', { name: 'Уреди клиент' }),
    ).toBeInTheDocument();
});
it('does not request client data for selected-location staff or without view permission', () => {
    const { unmount } = render(
        <Members
            organization={{ ...organization, location_scope: 'selected' }}
        />,
    );
    expect(screen.getByRole('alert')).toHaveTextContent(
        'пристап до сите локации',
    );
    expect(api.post).not.toHaveBeenCalled();
    unmount();
    render(<Members organization={{ ...organization, permissions: [] }} />);
    expect(api.post).not.toHaveBeenCalled();
});
it('handles directory and profile API failures without exposing another profile', async () => {
    vi.mocked(api.post)
        .mockRejectedValueOnce(new ApiError(403, 'Немате пристап'))
        .mockResolvedValue({ data: [client], meta });
    render(<Members organization={organization} />);
    expect(await screen.findByRole('alert')).toHaveTextContent(
        'Немате пристап',
    );
    await userEvent.click(screen.getByRole('button', { name: 'Пребарај' }));
    vi.mocked(api.get).mockRejectedValue(new ApiError(404, 'Не е пронајдено'));
    await userEvent.click(
        await screen.findByRole('button', {
            name: 'Отвори профил Тест Клиент',
        }),
    );
    await waitFor(() =>
        expect(screen.getByRole('alert')).toHaveTextContent('Не е пронајдено'),
    );
    expect(
        screen.queryByRole('heading', { name: 'Профил на клиент' }),
    ).not.toBeInTheDocument();
});

it('opens a permitted full profile from an operations deep link', async () => {
    render(<Members organization={organization} memberId="client" />);
    expect(
        await screen.findByRole('heading', { name: 'Тест Клиент' }),
    ).toBeVisible();
    expect(api.get).toHaveBeenCalledWith(
        '/api/v1/organizations/org/members/client',
        expect.any(AbortSignal),
    );
    expect(api.post).not.toHaveBeenCalled();
});
it('rejects a full-profile deep link for selected-location staff', () => {
    render(
        <Members
            organization={{ ...organization, location_scope: 'selected' }}
            memberId="client"
        />,
    );
    expect(screen.getByRole('alert')).toBeVisible();
    expect(api.get).not.toHaveBeenCalled();
});
