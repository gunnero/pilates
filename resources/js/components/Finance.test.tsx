import { beforeEach, expect, it, vi } from 'vitest';
import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { Finance } from './Finance';
import { parseMoney, money } from '../lib/money';
import { api, ApiError, type Organization } from '../lib/api';
import i18n from '../lib/i18n';
vi.mock('../lib/api', async (original) => ({
    ...(await original<typeof import('../lib/api')>()),
    api: { get: vi.fn(), post: vi.fn(), patch: vi.fn() },
}));
const org: Organization = {
    id: 'org',
    name: 'Studio',
    slug: 's',
    status: 'active',
    locale: 'en',
    timezone: 'Europe/Skopje',
    currency: 'MKD',
    membership_id: 'a',
    location_scope: 'all',
    permissions: [
        'memberships.view',
        'memberships.manage',
        'payments.view',
        'payments.record',
        'payments.correct',
    ],
};
const membership = {
    id: 'm',
    member: { id: 'client', display_name: 'Ana Client' },
    plan_name: 'Monthly',
    starts_on: '2026-09-01',
    ends_on: '2026-09-30',
    status: 'active',
    agreed_price_minor: 150000,
    net_paid_minor: 0,
    balance_minor: 150000,
    payment_status: 'unpaid',
};
const plan = {
    id: 'p',
    name: 'Monthly',
    description: null,
    price_minor: 150000,
    duration_unit: 'months',
    duration_count: 1,
    status: 'active',
};
const payment = {
    id: 'cash',
    member: membership.member,
    amount_minor: 150000,
    paid_at: '2026-09-24T10:00:00Z',
    recorded_by: { name: 'Cashier' },
    status: 'recorded',
    reversal: null,
};
const meta = { current_page: 1, last_page: 1, total: 1, per_page: 25 };
beforeEach(async () => {
    vi.resetAllMocks();
    await i18n.changeLanguage('en');
    vi.mocked(api.get).mockImplementation(async (path) =>
        path.includes('membership-plans')
            ? { data: [plan], meta }
            : path.includes('cash-payments')
              ? { data: [payment], meta }
              : path.endsWith('/memberships/m')
                ? { data: membership }
                : { data: [membership], meta },
    );
    vi.mocked(api.post).mockResolvedValue({ data: {} });
});
it('parses integer money without rounding and retains overpayment', () => {
    expect(parseMoney('1500,25')).toBe(150025);
    expect(parseMoney('0.01')).toBe(1);
    for (const v of ['1.001', '1e3', '-1', '1000000.01'])
        expect(parseMoney(v)).toBeNull();
    expect(money(-1)).toBe('-0.01 MKD');
});
it('does not request or display finance without grants', () => {
    render(
        <Finance organization={{ ...org, permissions: ['attendance.view'] }} />,
    );
    expect(api.get).not.toHaveBeenCalled();
    expect(screen.queryByText('Memberships')).not.toBeInTheDocument();
});
it('renders directory balance and filters', async () => {
    const user = userEvent.setup();
    render(<Finance organization={org} />);
    expect(await screen.findByText('Ana Client')).toBeVisible();
    expect(screen.getByText('Balance: 1500.00 MKD')).toBeVisible();
    await user.selectOptions(screen.getByLabelText('Payment status'), 'unpaid');
    await waitFor(() =>
        expect(api.get).toHaveBeenLastCalledWith(
            expect.stringContaining('payment_state=unpaid'),
            expect.any(AbortSignal),
        ),
    );
});
it('basic membership viewers never request payments or display amount', async () => {
    const user = userEvent.setup();
    render(
        <Finance
            organization={{ ...org, permissions: ['memberships.view'] }}
        />,
    );
    await user.click(
        await screen.findByRole('button', { name: 'Membership details' }),
    );
    expect(screen.queryByText('1500.00 MKD')).not.toBeInTheDocument();
    expect(
        screen.queryByRole('button', { name: 'Record cash payment' }),
    ).not.toBeInTheDocument();
    expect(
        vi
            .mocked(api.get)
            .mock.calls.some(([path]) => path.includes('cash-payments')),
    ).toBe(false);
});
it('requires confirmation with client amount and period then sends integer payment', async () => {
    const user = userEvent.setup();
    render(<Finance organization={org} />);
    await user.click(
        await screen.findByRole('button', { name: 'Membership details' }),
    );
    await user.click(
        screen.getByRole('button', { name: 'Record cash payment' }),
    );
    await user.type(screen.getByLabelText('Amount (MKD)'), '1500.25');
    await user.click(screen.getByRole('button', { name: 'Review payment' }));
    const confirmation = screen.getByRole('group', {
        name: 'Confirm cash received',
    });
    expect(confirmation).toHaveTextContent('Ana Client');
    expect(confirmation).toHaveTextContent('1500.25 MKD');
    expect(confirmation).toHaveTextContent('2026-09-01 – 2026-09-30');
    expect(api.post).not.toHaveBeenCalled();
    await user.click(
        within(confirmation).getByRole('button', {
            name: 'Confirm cash received',
        }),
    );
    expect(api.post).toHaveBeenCalledWith(
        expect.stringContaining('/cash-payments'),
        expect.objectContaining({
            membership_id: 'm',
            amount_minor: 150025,
            request_id: expect.any(String),
        }),
    );
});
it('requires reason and confirmation for reversal and displays retained evidence', async () => {
    const user = userEvent.setup();
    render(<Finance organization={org} />);
    await user.click(
        await screen.findByRole('button', { name: 'Membership details' }),
    );
    await user.click(
        await screen.findByRole('button', { name: 'Reverse payment' }),
    );
    expect(
        screen.getByRole('button', { name: 'Confirm reversal' }),
    ).toBeDisabled();
    await user.selectOptions(screen.getByLabelText('Reason'), 'wrong_member');
    await user.click(screen.getByRole('button', { name: 'Confirm reversal' }));
    expect(api.post).toHaveBeenCalledWith(
        expect.stringContaining('/cash/reverse'),
        { reason: 'wrong_member' },
    );
});
it('shows validation and conflict errors while retaining request id for retry', async () => {
    const user = userEvent.setup();
    vi.mocked(api.post).mockRejectedValue(
        new ApiError(409, 'Membership cancelled.'),
    );
    render(<Finance organization={org} />);
    await user.click(
        await screen.findByRole('button', { name: 'Membership details' }),
    );
    await user.click(
        screen.getByRole('button', { name: 'Record cash payment' }),
    );
    await user.type(screen.getByLabelText('Amount (MKD)'), '1');
    await user.click(screen.getByRole('button', { name: 'Review payment' }));
    await user.click(
        screen.getByRole('button', { name: 'Confirm cash received' }),
    );
    expect(await screen.findByRole('alert')).toHaveTextContent(
        'Membership cancelled.',
    );
    await user.click(
        screen.getByRole('button', { name: 'Confirm cash received' }),
    );
    expect(vi.mocked(api.post).mock.calls[0][1]).toEqual(
        vi.mocked(api.post).mock.calls[1][1],
    );
});
it('renders and creates a plan with integer price', async () => {
    const user = userEvent.setup();
    render(<Finance organization={org} />);
    await user.click(screen.getByRole('button', { name: 'Membership plans' }));
    await screen.findByRole('heading', { name: 'Monthly' });
    await user.click(screen.getByRole('button', { name: 'New plan' }));
    await user.type(screen.getByLabelText('Plan name'), 'Quarter');
    await user.type(screen.getByLabelText('Agreed amount (MKD)'), '3000');
    await user.click(screen.getByRole('button', { name: 'Save changes' }));
    expect(api.post).toHaveBeenCalledWith(
        expect.stringContaining('/membership-plans'),
        expect.objectContaining({ name: 'Quarter', price_minor: 300000 }),
    );
});
it('previews dates before assigning membership in member profile', async () => {
    const user = userEvent.setup();
    vi.mocked(api.post).mockImplementation(async (path) =>
        path.endsWith('/preview')
            ? { data: { ends_on: '2026-10-23', plan } }
            : { data: {} },
    );
    render(<Finance organization={org} memberId="client" />);
    await user.click(screen.getByRole('button', { name: 'Add membership' }));
    await user.selectOptions(
        await screen.findByLabelText('Membership plan'),
        'p',
    );
    await screen.findByText(/Proposed period and price/);
    await user.click(
        screen.getByRole('button', { name: 'Confirm membership' }),
    );
    expect(api.post).toHaveBeenCalledWith(
        expect.stringMatching(/\/memberships$/),
        expect.objectContaining({ member_id: 'client', plan_id: 'p' }),
    );
});
it('renders empty state', async () => {
    vi.mocked(api.get).mockResolvedValue({ data: [], meta });
    render(<Finance organization={org} />);
    expect(
        await screen.findByText('No memberships match these filters.'),
    ).toBeVisible();
});
