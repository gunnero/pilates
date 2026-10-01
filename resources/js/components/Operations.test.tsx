import { beforeEach, expect, it, vi } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter } from 'react-router-dom';
import { Operations } from './Operations';
import { api, ApiError, type Organization } from '../lib/api';
import i18n from '../lib/i18n';
vi.mock('../lib/api', async (original) => ({
    ...(await original<typeof import('../lib/api')>()),
    api: { get: vi.fn(), post: vi.fn() },
}));
const org: Organization = {
    id: 'o',
    name: 'Studio',
    slug: 's',
    status: 'active',
    locale: 'en',
    timezone: 'Europe/Skopje',
    currency: 'MKD',
    membership_id: 'm',
    location_scope: 'selected',
    permissions: ['sessions.view', 'attendance.view'],
};
const data = {
    data: [],
    summary: { sessions: 0, reserved: 0 },
    alerts: [],
    alerts_truncated: false,
    communication: null,
    today: '2026-10-01',
    from: '2026-10-01',
    until: '2026-10-01',
    timezone: 'Europe/Skopje',
    as_of: '2026-10-01T10:00:00Z',
    meta: { current_page: 1, last_page: 1, total: 0 },
};
beforeEach(async () => {
    vi.resetAllMocks();
    await i18n.changeLanguage('en');
    vi.mocked(api.get).mockResolvedValue(data);
});
it('uses server studio date and renders bounded empty state', async () => {
    render(
        <MemoryRouter>
            <Operations organization={org} />
        </MemoryRouter>,
    );
    expect(
        await screen.findByText('Europe/Skopje', { exact: false }),
    ).toBeVisible();
    expect(screen.getByLabelText('From')).toHaveValue('2026-10-01');
    expect(screen.getByRole('button', { name: 'Next' })).toBeDisabled();
});
it('denies without required attendance permission before loading', () => {
    render(
        <MemoryRouter>
            <Operations
                organization={{ ...org, permissions: ['sessions.view'] }}
            />
        </MemoryRouter>,
    );
    expect(api.get).not.toHaveBeenCalled();
});
it('retains financial restriction and clears data on failed refresh', async () => {
    render(
        <MemoryRouter>
            <Operations organization={org} />
        </MemoryRouter>,
    );
    await screen.findByText('Europe/Skopje', { exact: false });
    vi.mocked(api.get).mockRejectedValue(new ApiError(403, 'Access revoked'));
    await userEvent.click(screen.getByRole('button', { name: 'Refresh' }));
    expect(await screen.findByRole('alert')).toHaveTextContent(
        'Access revoked',
    );
    expect(screen.queryByText('Europe/Skopje', { exact: false })).toBeNull();
});
it('does not apply an aborted previous tenant response', async () => {
    let resolve!: (value: typeof data) => void;
    vi.mocked(api.get).mockReturnValueOnce(
        new Promise((r) => {
            resolve = r;
        }),
    );
    const view = render(
        <MemoryRouter>
            <Operations organization={org} />
        </MemoryRouter>,
    );
    view.rerender(
        <MemoryRouter>
            <Operations organization={{ ...org, id: 'other' }} />
        </MemoryRouter>,
    );
    await screen.findByText('Europe/Skopje', { exact: false });
    resolve({ ...data, timezone: 'Private old studio' });
    await waitFor(() =>
        expect(
            screen.queryByText('Private old studio', { exact: false }),
        ).toBeNull(),
    );
});
it('submits valid date filters without member identity in URL', async () => {
    render(
        <MemoryRouter>
            <Operations organization={org} />
        </MemoryRouter>,
    );
    await screen.findByText('Europe/Skopje', { exact: false });
    await userEvent.click(screen.getByRole('button', { name: 'Search' }));
    await waitFor(() =>
        expect(api.get).toHaveBeenLastCalledWith(
            expect.stringContaining('from=2026-10-01&until=2026-10-01'),
            expect.any(AbortSignal),
        ),
    );
});
