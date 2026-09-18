import { afterEach, expect, it, vi } from 'vitest';
import { api, ApiError } from './api';
afterEach(() => {
    vi.unstubAllGlobals();
    document.cookie = 'XSRF-TOKEN=; Max-Age=0';
});
it('uses session cookies and CSRF token for unsafe requests', async () => {
    document.cookie = 'XSRF-TOKEN=encoded%3Dtoken';
    const fetchMock = vi
        .fn()
        .mockResolvedValue(new Response(null, { status: 204 }));
    vi.stubGlobal('fetch', fetchMock);
    await api.post('/auth/logout');
    expect(fetchMock).toHaveBeenNthCalledWith(
        1,
        '/sanctum/csrf-cookie',
        expect.objectContaining({ credentials: 'same-origin' }),
    );
    expect(fetchMock).toHaveBeenNthCalledWith(
        2,
        '/auth/logout',
        expect.objectContaining({
            method: 'POST',
            headers: expect.objectContaining({
                'X-XSRF-TOKEN': 'encoded=token',
                'Accept-Language': 'mk',
            }),
        }),
    );
});
it('preserves status and field validation errors', async () => {
    vi.stubGlobal(
        'fetch',
        vi.fn().mockResolvedValue(
            new Response(
                JSON.stringify({
                    message: 'Invalid',
                    errors: { email: ['Required'] },
                }),
                { status: 422 },
            ),
        ),
    );
    await expect(api.get('/api/v1/me')).rejects.toMatchObject({
        status: 422,
        errors: { email: ['Required'] },
    });
});
it('handles non-JSON server errors safely', async () => {
    vi.stubGlobal(
        'fetch',
        vi
            .fn()
            .mockResolvedValue(
                new Response('<html>Failure</html>', { status: 503 }),
            ),
    );
    await expect(api.get('/api/v1/me')).rejects.toBeInstanceOf(ApiError);
});
