import { beforeEach, describe, expect, it, vi } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter } from 'react-router-dom';
import App from './App';
import { api, ApiError } from './lib/api';
import i18n from './lib/i18n';
vi.mock('./lib/api', async (original) => ({
    ...(await original<typeof import('./lib/api')>()),
    api: { get: vi.fn(), post: vi.fn() },
}));
const profile = {
    data: {
        id: 'user',
        name: 'Ana',
        organizations: [{ id: 'studio', name: 'Test Studio' }],
    },
};
beforeEach(async () => {
    vi.resetAllMocks();
    await i18n.changeLanguage('mk');
});
function mount(path = '/login') {
    return render(
        <MemoryRouter initialEntries={[path]}>
            <App />
        </MemoryRouter>,
    );
}
describe('studio foundation', () => {
    it('renders Macedonian by default and switches language', async () => {
        mount();
        expect(
            screen.getByRole('heading', { name: 'Добредојдовте во GT' }),
        ).toBeInTheDocument();
        await userEvent.selectOptions(screen.getByLabelText('Јазик'), 'en');
        expect(
            screen.getByRole('heading', { name: 'Welcome to GT' }),
        ).toBeInTheDocument();
        expect(document.documentElement.lang).toBe('en');
    });
    it('submits login credentials and displays server validation errors', async () => {
        vi.mocked(api.post).mockRejectedValue(
            new ApiError(422, 'Invalid', { email: ['Invalid credentials'] }),
        );
        mount();
        await userEvent.type(
            screen.getByLabelText('Е-пошта'),
            'ana@example.test',
        );
        await userEvent.type(screen.getByLabelText('Лозинка'), 'test-password');
        await userEvent.click(
            screen.getByRole('button', { name: 'Најави се' }),
        );
        expect(api.post).toHaveBeenCalledWith('/auth/login', {
            email: 'ana@example.test',
            password: 'test-password',
        });
        expect(await screen.findByRole('alert')).toHaveTextContent(
            'Invalid credentials',
        );
    });
    it('redirects anonymous protected requests to login', async () => {
        vi.mocked(api.get).mockRejectedValue(
            new ApiError(401, 'Unauthenticated'),
        );
        mount('/payments');
        expect(
            await screen.findByRole('heading', { name: 'Добредојдовте во GT' }),
        ).toBeInTheDocument();
    });
    it('handles network failure without claiming the user is signed out', async () => {
        vi.mocked(api.get).mockRejectedValue(new TypeError('Network failure'));
        mount('/');
        expect(
            await screen.findByRole('button', { name: 'Обиди се повторно' }),
        ).toBeInTheDocument();
        expect(screen.queryByLabelText('Лозинка')).not.toBeInTheDocument();
    });
    it('renders only authorized studio context and placeholder navigation', async () => {
        vi.mocked(api.get).mockImplementation(async (path) =>
            path === '/api/v1/me'
                ? profile
                : {
                      data: {
                          id: 'studio',
                          name: 'Test Studio',
                          locale: 'mk',
                          timezone: 'Europe/Skopje',
                          currency: 'MKD',
                      },
                  },
        );
        mount('/');
        expect(
            await screen.findByRole('heading', {
                name: 'Простор за вашето студио',
            }),
        ).toBeInTheDocument();
        await userEvent.click(screen.getByRole('link', { name: /Плаќања/ }));
        expect(
            await screen.findByText('Овој дел сè уште не е достапен.'),
        ).toBeInTheDocument();
        expect(screen.queryByText(/MKD/)).not.toBeInTheDocument();
    });
    it('shows denial without rendering tenant content', async () => {
        vi.mocked(api.get).mockImplementation(async (path) => {
            if (path === '/api/v1/me') return profile;
            throw new ApiError(403, 'Denied');
        });
        mount('/');
        expect(await screen.findByRole('alert')).toHaveTextContent(
            'Немате пристап',
        );
        expect(
            screen.queryByRole('heading', { name: 'Простор за вашето студио' }),
        ).not.toBeInTheDocument();
    });
    it('supports accounts without a studio and logs out', async () => {
        vi.mocked(api.get).mockResolvedValue({
            data: { ...profile.data, organizations: [] },
        });
        vi.mocked(api.post).mockResolvedValue(undefined);
        mount('/');
        expect(
            await screen.findByText(/Немате доделен пристап/),
        ).toBeInTheDocument();
        await userEvent.click(
            screen.getByRole('button', { name: 'Одјави се' }),
        );
        await waitFor(() =>
            expect(api.post).toHaveBeenCalledWith('/auth/logout'),
        );
        expect(
            await screen.findByRole('heading', { name: 'Добредојдовте во GT' }),
        ).toBeInTheDocument();
    });
});
