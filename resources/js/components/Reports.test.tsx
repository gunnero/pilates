import { beforeEach, expect, it, vi } from 'vitest';
import { act, render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter } from 'react-router-dom';
import { Reports } from './Reports';
import { api, ApiError, type Organization } from '../lib/api';
import i18n from '../lib/i18n';
vi.mock('../lib/api', async (original) => ({
    ...(await original<typeof import('../lib/api')>()),
    api: { get: vi.fn() },
}));
const org: Organization = {
    id: 'o',
    name: 'Studio',
    slug: 'studio',
    status: 'active',
    timezone: 'Europe/Skopje',
    locale: 'en',
    currency: 'MKD',
    membership_id: 'a',
    location_scope: 'all',
    role: 'owner',
    permissions: [
        'reports.view',
        'payments.view',
        'members.view',
        'memberships.view',
        'sessions.view',
        'attendance.view',
    ],
};
const result = {
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
        rows: [{ period: '2026-10-01', net_minor: '4000' }],
        columns: ['period', 'net_minor'],
        options: {},
        comparison: null,
    },
};
beforeEach(async () => {
    vi.resetAllMocks();
    await i18n.changeLanguage('en');
    vi.mocked(api.get).mockResolvedValue(result);
});
function mount(organization = org) {
    return render(
        <MemoryRouter>
            <Reports organization={organization} />
        </MemoryRouter>,
    );
}
it('renders authoritative snapshot, exact values, table and exports', async () => {
    mount();
    expect(
        await screen.findByText('Europe/Skopje', { exact: false }),
    ).toBeVisible();
    expect(
        screen.getByRole('region', { name: 'Report values' }),
    ).toHaveTextContent('40.00');
    expect(
        screen.getByRole('button', { name: 'Download Excel' }),
    ).toBeEnabled();
    expect(screen.getByLabelText('From')).toHaveValue('2026-10-01');
});
it('does not fetch without report access', () => {
    mount({ ...org, permissions: [] });
    expect(api.get).not.toHaveBeenCalled();
});
it('trainer choices never request financial or whole-studio population', async () => {
    mount({ ...org, role: 'trainer' });
    await screen.findByText('Europe/Skopje', { exact: false });
    expect(
        screen.queryByRole('option', { name: 'Net cash collections' }),
    ).not.toBeInTheDocument();
    expect(vi.mocked(api.get).mock.calls[0][0]).toContain('report=attendance');
});
it('revocation clears old values and does not show a false zero', async () => {
    mount();
    await screen.findByText('Europe/Skopje', { exact: false });
    vi.mocked(api.get).mockRejectedValue(new ApiError(403, 'Access revoked'));
    await userEvent.click(screen.getByRole('button', { name: 'Refresh' }));
    expect(await screen.findByRole('alert')).toHaveTextContent(
        'Access revoked',
    );
    expect(screen.queryByRole('table')).not.toBeInTheDocument();
});
it('discards an old report response after filter replacement', async () => {
    let resolve!: (value: typeof result) => void;
    vi.mocked(api.get).mockImplementationOnce(
        () =>
            new Promise((r) => {
                resolve = r;
            }),
    );
    mount();
    await userEvent.selectOptions(
        screen.getByLabelText('Report'),
        'settlement',
    );
    await screen.findByText('Europe/Skopje', { exact: false });
    await act(async () =>
        resolve({
            ...result,
            data: { ...result.data, totals: { net_minor: '99999' } },
        }),
    );
    expect(screen.queryByText('99999')).not.toBeInTheDocument();
});
it('download failure stays visible and allows a retry', async () => {
    vi.stubGlobal(
        'fetch',
        vi.fn().mockResolvedValue(
            new Response(
                JSON.stringify({
                    message: 'Narrow the period before exporting.',
                }),
                { status: 422 },
            ),
        ),
    );
    mount();
    await screen.findByText('Europe/Skopje', { exact: false });
    await userEvent.click(screen.getByRole('button', { name: 'Download CSV' }));
    expect(await screen.findByRole('alert')).toHaveTextContent(
        'Narrow the period before exporting.',
    );
    expect(screen.getByRole('button', { name: 'Download CSV' })).toBeEnabled();
    vi.unstubAllGlobals();
});
it('applies a studio-day preset and explains incomplete custom comparisons', async () => {
    mount();
    await screen.findByText('Europe/Skopje', { exact: false });
    await userEvent.click(screen.getByRole('button', { name: 'Last 7 days' }));
    expect(vi.mocked(api.get).mock.calls.at(-1)?.[0]).toContain(
        'from=2026-10-06',
    );
    await screen.findByText('Europe/Skopje', { exact: false });
    await userEvent.selectOptions(
        screen.getByLabelText('Comparison', { exact: true }),
        'custom',
    );
    expect(screen.getByRole('status')).toHaveTextContent(
        'Choose both comparison dates',
    );
});
it('creates a fresh download signal in Strict Mode and aborts it on unmount', async () => {
    const fetchMock = vi.fn(() => new Promise<Response>(() => {}));
    vi.stubGlobal('fetch', fetchMock);
    const { StrictMode } = await import('react');
    const view = render(
        <StrictMode>
            <MemoryRouter>
                <Reports organization={org} />
            </MemoryRouter>
        </StrictMode>,
    );
    await screen.findByText('Europe/Skopje', { exact: false });
    await userEvent.click(screen.getByRole('button', { name: 'Download CSV' }));
    const signal = (
        fetchMock.mock.calls[0] as unknown as [string, RequestInit]
    )[1].signal!;
    expect(signal.aborted).toBe(false);
    view.unmount();
    expect(signal.aborted).toBe(true);
    vi.unstubAllGlobals();
});
it('retains a scoped plan while choosing a custom comparison', async () => {
    vi.mocked(api.get).mockResolvedValue({
        data: {
            ...result.data,
            options: {
                plan_id: {
                    rows: [{ id: 'plan-fixture', label: 'Scoped plan' }],
                    truncated: false,
                },
            },
        },
    });
    mount();
    await screen.findByText('Europe/Skopje', { exact: false });
    await userEvent.selectOptions(
        screen.getByRole('combobox', { name: 'Plan' }),
        'plan-fixture',
    );
    await userEvent.click(screen.getByRole('button', { name: 'Apply' }));
    await screen.findByText('Europe/Skopje', { exact: false });
    await userEvent.selectOptions(
        screen.getByRole('combobox', { name: 'Comparison' }),
        'custom',
    );
    expect(screen.getByRole('combobox', { name: 'Plan' })).toHaveValue(
        'plan-fixture',
    );
    expect(screen.getByLabelText('From')).toHaveValue('2026-10-01');
});
