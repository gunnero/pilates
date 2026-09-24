import { beforeEach, expect, it, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemberInvitations } from './MemberInvitations';
import { api, type Organization } from '../lib/api';
import i18n from '../lib/i18n';
vi.mock('../lib/api', async (original) => ({
    ...(await original<typeof import('../lib/api')>()),
    api: { get: vi.fn(), post: vi.fn() },
}));
const organization = {
    id: 'org',
    location_scope: 'all',
    permissions: ['members.view', 'members.update', 'members.status.manage'],
} as Organization;
beforeEach(async () => {
    vi.resetAllMocks();
    await i18n.changeLanguage('en');
    vi.mocked(api.get).mockResolvedValue({
        linked: false,
        data: [
            {
                id: 'invite',
                status: 'pending',
                expires_at: '2026-10-01T10:00:00Z',
            },
        ],
        meta: { last_page: 1 },
    });
});
it('issues a private invitation and can revoke pending access', async () => {
    render(<MemberInvitations organization={organization} memberId="member" />);
    const user = userEvent.setup();
    await user.type(
        await screen.findByLabelText('Email'),
        'verified@example.test',
    );
    vi.mocked(api.post).mockResolvedValue({ data: { delivery: 'accepted' } });
    await user.click(screen.getByRole('button', { name: 'Send invitation' }));
    expect(api.post).toHaveBeenCalledWith(
        '/api/v1/organizations/org/members/member/invitations',
        { email: 'verified@example.test' },
    );
    expect(await screen.findByRole('status')).toHaveTextContent(
        'Invitation accepted',
    );
    await user.click(screen.getByRole('button', { name: 'Revoke invitation' }));
    expect(api.post).toHaveBeenCalledWith(
        '/api/v1/organizations/org/member-invitations/invite/revoke',
    );
});
it('reports failed delivery and existing links', async () => {
    render(<MemberInvitations organization={organization} memberId="member" />);
    const user = userEvent.setup();
    await user.type(
        await screen.findByLabelText('Email'),
        'verified@example.test',
    );
    vi.mocked(api.post).mockResolvedValue({ data: { delivery: 'failed' } });
    vi.mocked(api.get).mockResolvedValue({
        linked: true,
        data: [],
        meta: { last_page: 1 },
    });
    await user.click(screen.getByRole('button', { name: 'Send invitation' }));
    expect(await screen.findByRole('status')).toHaveTextContent(
        'Delivery failed',
    );
    expect(
        await screen.findByText('An account is linked to this member.'),
    ).toBeInTheDocument();
    expect(
        screen.queryByRole('button', { name: 'Send invitation' }),
    ).not.toBeInTheDocument();
});
it('requires all invitation grants and all-location scope', () => {
    render(
        <MemberInvitations
            organization={{ ...organization, location_scope: 'selected' }}
            memberId="member"
        />,
    );
    expect(api.get).not.toHaveBeenCalled();
    expect(screen.queryByText('Member account access')).not.toBeInTheDocument();
});
