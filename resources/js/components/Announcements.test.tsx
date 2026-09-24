import { beforeEach, expect, it, vi } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { Announcements } from './Announcements';
import { api, ApiError } from '../lib/api';
import i18n from '../lib/i18n';
vi.mock('../lib/api', async (original) => ({
    ...(await original<typeof import('../lib/api')>()),
    api: { get: vi.fn(), post: vi.fn() },
}));
beforeEach(async () => {
    vi.resetAllMocks();
    await i18n.changeLanguage('en');
    vi.mocked(api.get).mockResolvedValue({ data: [] });
    vi.mocked(api.post).mockResolvedValue({ data: { id: 'announcement' } });
});
it('queues localized plain text and preserves one request identity after a failure', async () => {
    const user = userEvent.setup();
    render(<Announcements organization="org" />);
    await user.type(screen.getByLabelText('Title (MK)'), 'Студио');
    await user.type(screen.getByLabelText('Title (EN)'), 'Studio');
    await user.type(screen.getByLabelText('Message (MK)'), 'Порака');
    await user.type(screen.getByLabelText('Message (EN)'), 'Message');
    vi.mocked(api.post).mockRejectedValueOnce(new ApiError(503, 'Unavailable'));
    await user.click(
        screen.getByRole('button', { name: 'Queue announcement' }),
    );
    expect(await screen.findByRole('alert')).toBeVisible();
    expect(screen.getByLabelText('Message (EN)')).toHaveValue('Message');
    await user.click(
        screen.getByRole('button', { name: 'Queue announcement' }),
    );
    await waitFor(() => expect(api.post).toHaveBeenCalledTimes(2));
    expect(vi.mocked(api.post).mock.calls[0]).toEqual(
        vi.mocked(api.post).mock.calls[1],
    );
    expect(api.post).toHaveBeenCalledWith(
        '/api/v1/organizations/org/announcements',
        expect.objectContaining({ title_en: 'Studio', message_mk: 'Порака' }),
    );
});
it('shows safe status and cancels pending announcements', async () => {
    vi.mocked(api.get).mockResolvedValue({
        data: [
            {
                id: 'a',
                title_en: 'Update',
                title_mk: 'Промена',
                state: 'pending',
                recipients: 20,
            },
        ],
    });
    const user = userEvent.setup();
    render(<Announcements organization="org" />);
    expect(await screen.findByText('Update')).toBeVisible();
    expect(screen.getByText('Queued · Recipients: 20')).toBeVisible();
    await user.click(screen.getByRole('button', { name: 'Cancel' }));
    expect(api.post).toHaveBeenCalledWith(
        '/api/v1/organizations/org/announcements/a/cancel',
    );
});
it('renders Macedonian controls and authorization failure', async () => {
    await i18n.changeLanguage('mk');
    vi.mocked(api.get).mockRejectedValue(new ApiError(403, 'Denied'));
    render(<Announcements organization="org" />);
    expect(
        screen.getByRole('button', { name: 'Закажи известување' }),
    ).toBeVisible();
    expect(await screen.findByRole('alert')).toBeVisible();
});
