import { beforeEach, expect, it, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { StudioPolicies, type Settings } from './StudioPolicies';
import { api, ApiError } from '../lib/api';
import i18n from '../lib/i18n';
vi.mock('../lib/api', async (original) => ({
    ...(await original<typeof import('../lib/api')>()),
    api: { get: vi.fn(), patch: vi.fn() },
}));
const data: Settings = {
    effective: {
        self_reservation_enabled: true,
        reminder_lead_hours: 24,
        check_in_early_minutes: 30,
        reservation_cutoff_minutes: 0,
    },
    overrides: {
        self_reservation_enabled: null,
        reminder_lead_hours: null,
        check_in_early_minutes: null,
        reservation_cutoff_minutes: null,
    },
    catalog: {
        self_reservation_enabled: {
            type: 'boolean',
            default: true,
            min: null,
            max: null,
            category: 'member_app',
        },
        reminder_lead_hours: {
            type: 'integer',
            default: 24,
            min: 1,
            max: 72,
            category: 'notifications',
        },
        check_in_early_minutes: {
            type: 'integer',
            default: 30,
            min: 0,
            max: 120,
            category: 'attendance',
        },
        reservation_cutoff_minutes: {
            type: 'integer',
            default: 0,
            min: 0,
            max: 1440,
            category: 'reservations',
        },
    },
};
beforeEach(async () => {
    vi.resetAllMocks();
    await i18n.changeLanguage('en');
    vi.mocked(api.get).mockResolvedValue({ data });
    vi.mocked(api.patch).mockResolvedValue({ data });
});
it('loads sections, defaults and bounds and confirms only the changed typed fields', async () => {
    render(<StudioPolicies organization="A" manage />);
    const toggle = await screen.findByLabelText('Member self-reservation');
    expect(toggle).toHaveValue('default');
    expect(
        screen.getByLabelText('Session reminder lead time (hours)'),
    ).toHaveAttribute('max', '72');
    expect(screen.getByRole('group', { name: 'Reservations' })).toBeVisible();
    expect(
        screen.getByRole('group', { name: 'Attendance & Check-In' }),
    ).toBeVisible();
    await userEvent.selectOptions(toggle, 'false');
    await userEvent.click(screen.getByRole('button', { name: 'Save changes' }));
    expect(api.patch).not.toHaveBeenCalled();
    await userEvent.click(
        screen.getByRole('button', { name: 'Apply policies' }),
    );
    expect(api.patch).toHaveBeenCalledWith('/api/v1/organizations/A/settings', {
        self_reservation_enabled: false,
    });
    expect(await screen.findByRole('status')).toHaveTextContent(
        'Changes saved.',
    );
});
it('preserves failed changes and renders server validation', async () => {
    vi.mocked(api.patch).mockRejectedValue(
        new ApiError(422, 'Invalid', {
            reminder_lead_hours: ['Out of bounds'],
        }),
    );
    render(<StudioPolicies organization="A" manage />);
    const lead = await screen.findByLabelText(
        'Session reminder lead time (hours)',
    );
    await userEvent.clear(lead);
    await userEvent.type(lead, '12');
    await userEvent.click(screen.getByRole('button', { name: 'Save changes' }));
    await userEvent.click(
        screen.getByRole('button', { name: 'Apply policies' }),
    );
    expect(await screen.findByRole('alert')).toHaveTextContent('Out of bounds');
    expect(lead).toHaveValue(12);
});
it('supports read-only controls, MK text and explicit reset', async () => {
    await i18n.changeLanguage('mk');
    const { unmount } = render(
        <StudioPolicies organization="A" manage={false} />,
    );
    expect(
        await screen.findByLabelText('Самостојна резервација'),
    ).toBeDisabled();
    expect(
        screen.queryByRole('button', { name: 'Зачувај промени' }),
    ).not.toBeInTheDocument();
    unmount();
    render(<StudioPolicies organization="A" manage />);
    await screen.findByLabelText('Самостојна резервација');
    await userEvent.click(
        screen.getAllByRole('button', {
            name: 'Користи стандардна вредност',
        })[0],
    );
    await userEvent.click(
        screen.getByRole('button', { name: 'Зачувај промени' }),
    );
    await userEvent.click(
        screen.getByRole('button', { name: 'Примени правила' }),
    );
    expect(api.patch).toHaveBeenCalledWith('/api/v1/organizations/A/settings', {
        reservation_cutoff_minutes: null,
    });
});
it('shows permission denial from the API without exposing a form', async () => {
    vi.mocked(api.get).mockRejectedValue(new ApiError(403, 'Denied'));
    render(<StudioPolicies organization="A" manage />);
    expect(await screen.findByRole('alert')).toHaveTextContent('Denied');
    expect(
        screen.queryByRole('button', { name: 'Save changes' }),
    ).not.toBeInTheDocument();
});
