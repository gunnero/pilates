import { beforeEach, expect, it, vi } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { Inbox } from './Notifications';
import { api, ApiError } from '../lib/api';
import i18n from '../lib/i18n';
vi.mock('../lib/api', async (original) => ({
    ...(await original<typeof import('../lib/api')>()),
    api: { get: vi.fn(), post: vi.fn(), patch: vi.fn() },
}));
const notice = {
    id: 'notice',
    title: 'Reservation confirmed',
    message: 'Your place is confirmed.',
    created_at: '2026-09-20T10:00:00Z',
    read_at: null,
    current: true,
};
const prefs = { reminders_enabled: true, expiry_reminders_enabled: true };
const meta = { current_page: 1, last_page: 1, per_page: 20, total: 1 };
beforeEach(async () => {
    vi.resetAllMocks();
    await i18n.changeLanguage('en');
    vi.mocked(api.get).mockImplementation(async (path) =>
        path.endsWith('/preferences')
            ? { data: prefs }
            : { data: [notice], unread_count: 1, meta },
    );
});
it('renders inbox and unread count and marks one read', async () => {
    const user = userEvent.setup();
    render(<Inbox organization="org" />);
    expect(await screen.findByText(notice.title)).toBeInTheDocument();
    expect(screen.getByText('Unread: 1')).toBeInTheDocument();
    await user.click(screen.getByRole('button', { name: 'Mark read' }));
    expect(api.post).toHaveBeenCalledWith(
        '/api/v1/organizations/org/notifications/notice/read',
    );
});
it('marks only unread items on the current page', async () => {
    const user = userEvent.setup();
    render(<Inbox organization="org" />);
    await screen.findByText(notice.title);
    await user.click(
        screen.getByRole('button', { name: 'Mark this page read' }),
    );
    expect(api.post).toHaveBeenCalledWith(
        '/api/v1/organizations/org/notifications/read',
        { ids: ['notice'] },
    );
});
it('renders empty inbox and disables bulk action', async () => {
    vi.mocked(api.get).mockImplementation(async (path) =>
        path.endsWith('/preferences')
            ? { data: prefs }
            : { data: [], unread_count: 0, meta },
    );
    render(<Inbox organization="org" />);
    expect(await screen.findByText('No notifications.')).toBeInTheDocument();
    expect(
        screen.getByRole('button', { name: 'Mark this page read' }),
    ).toBeDisabled();
});
it('paginates and resets to page one for unread filter', async () => {
    vi.mocked(api.get).mockImplementation(async (path) =>
        path.endsWith('/preferences')
            ? { data: prefs }
            : {
                  data: [notice],
                  unread_count: 1,
                  meta: { ...meta, last_page: 2 },
              },
    );
    const user = userEvent.setup();
    render(<Inbox organization="org" />);
    await screen.findByText(notice.title);
    await user.click(screen.getByRole('button', { name: 'Next' }));
    await waitFor(() =>
        expect(api.get).toHaveBeenCalledWith(
            expect.stringContaining('page=2'),
            expect.any(AbortSignal),
        ),
    );
    await screen.findByText(notice.title);
    await user.click(screen.getByLabelText('Unread only'));
    await waitFor(() =>
        expect(api.get).toHaveBeenCalledWith(
            expect.stringContaining('page=1&per_page=20&unread=1'),
            expect.any(AbortSignal),
        ),
    );
});
it('renders Macedonian controls and historical state', async () => {
    await i18n.changeLanguage('mk');
    vi.mocked(api.get).mockImplementation(async (path) =>
        path.endsWith('/preferences')
            ? { data: prefs }
            : {
                  data: [
                      {
                          ...notice,
                          title: 'Резервацијата е потврдена',
                          current: false,
                          read_at: '2026-09-20T11:00:00Z',
                      },
                  ],
                  unread_count: 0,
                  meta,
              },
    );
    render(<Inbox organization="org" />);
    expect(
        await screen.findByText('Резервацијата е потврдена'),
    ).toBeInTheDocument();
    expect(
        screen.getByText('Оваа порака опишува претходна состојба.'),
    ).toBeInTheDocument();
    expect(
        screen.queryByRole('button', { name: 'Означи како прочитано' }),
    ).not.toBeInTheDocument();
});
it('shows permission errors without marking a message read', async () => {
    vi.mocked(api.post).mockRejectedValue(new ApiError(403, 'Access denied'));
    const user = userEvent.setup();
    render(<Inbox organization="org" />);
    await screen.findByText(notice.title);
    await user.click(screen.getByRole('button', { name: 'Mark read' }));
    expect(await screen.findByRole('alert')).toHaveTextContent('Access denied');
    expect(screen.getByText('Unread: 1')).toBeInTheDocument();
});
it('updates optional reminder preferences', async () => {
    const user = userEvent.setup();
    render(<Inbox organization="org" />);
    await screen.findByText(notice.title);
    await user.click(screen.getByLabelText('Session reminders'));
    expect(api.patch).toHaveBeenCalledWith(
        '/api/v1/organizations/org/notifications/preferences',
        { ...prefs, reminders_enabled: false },
    );
});
it('saves channel preferences as a partial patch and marks all read', async () => {
    const user = userEvent.setup();
    render(<Inbox organization="org" />);
    const push = await screen.findByLabelText('Push notifications');
    await user.click(push);
    expect(api.patch).toHaveBeenCalledWith(
        '/api/v1/organizations/org/notifications/preferences',
        { push_enabled: false },
    );
    await user.click(
        await screen.findByRole('button', { name: 'Mark all as read' }),
    );
    expect(api.post).toHaveBeenCalledWith(
        '/api/v1/organizations/org/notifications/read-all',
    );
});
