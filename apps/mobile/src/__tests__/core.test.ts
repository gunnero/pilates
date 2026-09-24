import { ApiClient, ApiError, apiOrigin, pageQuery } from '../api/client';
import { MemberApi } from '../api/member';
import { SessionController } from '../auth/session';
import { parseLink, parsePushLink } from '../navigation/links';
import { DeviceLifecycle } from '../notifications/device';
import { createCache, memberKey } from '../hooks/cache';
import { en, mk } from '../locales/strings';
import { dateTime, wallTime, money } from '../utils/format';
const org = '01950000-0000-7000-8000-000000000001';
const id = '01950000-0000-7000-8000-000000000002';
function response(data: unknown, status = 200) {
    return { ok: status < 400, status, json: async () => data } as Response;
}
function deferred<T>() {
    let resolve!: (value: T) => void;
    const promise = new Promise<T>((r) => {
        resolve = r;
    });
    return { promise, resolve };
}
function setup() {
    const transport = jest.fn();
    const api = new MemberApi(
        new ApiClient('https://studio.example', transport),
    );
    let stored: string | null = null;
    const storage = {
        read: jest.fn(async () => stored),
        write: jest.fn(async (token: string) => {
            stored = token;
        }),
        clear: jest.fn(async () => {
            stored = null;
        }),
    };
    const cache = createCache();
    const controller = new SessionController(
        api,
        storage,
        () => {
            void cache.cancelQueries();
            cache.clear();
        },
        async () => {},
    );
    return { transport, api, storage, cache, controller };
}
afterEach(() => jest.restoreAllMocks());
test('release configuration requires a bare HTTPS origin and rejects credentials and arbitrary local HTTP', () => {
    expect(apiOrigin('https://studio.example', false, false)).toBe(
        'https://studio.example',
    );
    for (const input of [
        '',
        undefined,
        'http://studio.example',
        'https://a:b@studio.example',
        'https://studio.example/path',
        'https://studio.example?token=x',
        'file:///x',
    ])
        expect(() => apiOrigin(input, false, true)).toThrow(ApiError);
    expect(apiOrigin('http://10.0.2.2:8011', true, true)).toBe(
        'http://10.0.2.2:8011',
    );
    expect(() => apiOrigin('http://10.0.2.2:8011', false, true)).toThrow();
});
test.each([401, 403, 404, 409, 422, 429, 500])(
    'safe API error %s does not expose response details',
    async (status) => {
        const client = new ApiClient(
            'https://studio.example',
            jest.fn(async () =>
                response({ password: 'private', trace: 'private' }, status),
            ),
        );
        await expect(
            client.request('/api/v1/member/account'),
        ).rejects.not.toThrow('private');
    },
);
test('bearer is captured, cookies omitted, redirects rejected, and POST never retried', async () => {
    const transport = jest.fn(async () => {
        throw new Error('private stack');
    });
    const client = new ApiClient('https://studio.example', transport);
    client.token = 'sensitive';
    await expect(
        client.request('/api/v1/member/password', 'POST', {}),
    ).rejects.toMatchObject({ kind: 'network' });
    expect(transport).toHaveBeenCalledTimes(1);
    expect(transport.mock.calls[0]).toEqual([
        'https://studio.example/api/v1/member/password',
        expect.objectContaining({
            credentials: 'omit',
            redirect: 'error',
            headers: expect.objectContaining({
                Authorization: 'Bearer sensitive',
            }),
        }),
    ]);
});
test('timeout aborts the request without retry', async () => {
    const transport = jest.fn(
        (_url: string | URL | Request, init?: RequestInit) =>
            new Promise<Response>((_resolve, reject) =>
                init?.signal?.addEventListener('abort', () =>
                    reject(new Error()),
                ),
            ),
    );
    const client = new ApiClient('https://studio.example', transport, 5);
    await expect(
        client.request('/api/v1/member/account'),
    ).rejects.toMatchObject({ kind: 'network' });
    expect(transport).toHaveBeenCalledTimes(1);
});
test('all consumed endpoint contracts use member scope and bounded parameters', async () => {
    const calls: { path: string; method: string; body: unknown }[] = [];
    const transport = jest.fn(
        async (url: string | URL | Request, init?: RequestInit) => {
            calls.push({
                path: String(url).replace('https://studio.example', ''),
                method: init?.method ?? 'GET',
                body: init?.body ? JSON.parse(String(init.body)) : undefined,
            });
            return response({ data: [] });
        },
    );
    const api = new MemberApi(
        new ApiClient('https://studio.example', transport),
    );
    await api.account();
    await api.organizations(2);
    await api.login('a@example.test', 'pass');
    await api.activate('a@example.test', 't', 'pass');
    await api.forgot('a@example.test');
    await api.reset('a@example.test', 't', 'pass');
    await api.password('old', 'new');
    await api.logout();
    await api.switch(org);
    await api.profile(org);
    await api.saveLocale(org, 'en');
    await api.groups(org, 2);
    await api.group(org, id);
    await api.sessions(org, 2, 'reserved', id);
    await api.session(org, id);
    await api.reserve(org, id, true);
    await api.cancel(org, 'reservations', id);
    await api.cancel(org, 'waiting-list', id);
    await api.waiting(org);
    await api.attendance(org);
    await api.memberships(org);
    await api.payments(org);
    await api.notices(org);
    await api.notice(org, id);
    await api.read(org, [id]);
    await api.preferences(org);
    await api.savePreferences(org, {
        reminders_enabled: false,
        expiry_reminders_enabled: true,
    });
    expect(calls).toHaveLength(27);
    expect(calls[13].path).toContain(
        `sessions?per_page=25&page=2&relationship=reserved&group_id=${id}`,
    );
    expect(calls[15].body).toEqual({ allow_waiting: true });
    expect(calls[24].body).toEqual({ ids: [id] });
    expect(calls.every((c) => c.path.startsWith('/api/v1/'))).toBe(true);
    expect(
        calls
            .filter((c) => c.method === 'POST' || c.method === 'PATCH')
            .some((c) => JSON.stringify(c.body).includes('organization_id')),
    ).toBe(false);
    expect(() => pageQuery(10001)).toThrow();
    expect(() => api.group(org, '../staff')).toThrow();
});
test('valid secure restoration and organization switch clear old account/org cache', async () => {
    const { transport, api, storage, cache, controller } = setup();
    await storage.write('tokenA');
    transport.mockResolvedValue(
        response({ data: { id: 'A', name: 'Alice', email: 'a@example.test' } }),
    );
    await controller.restore();
    expect(controller.snapshot().account?.id).toBe('A');
    cache.setQueryData(memberKey('A', org, 'payments'), { private: 'A' });
    transport.mockResolvedValue(response({ data: {} }));
    await controller.select({
        id: org,
        name: 'Studio',
        timezone: 'Europe/Skopje',
        locale: 'mk',
        currency: 'MKD',
    });
    expect(cache.getQueryCache().getAll()).toHaveLength(0);
    expect(controller.snapshot().organization?.id).toBe(org);
    await controller.clear();
    expect(api.client.token).toBeNull();
    expect(await storage.read()).toBeNull();
    expect(controller.snapshot().account).toBeNull();
});
test('expired or revoked credential returns to anonymous and clears secure storage', async () => {
    const { transport, storage, controller } = setup();
    await storage.write('revoked');
    transport.mockResolvedValue(response({}, 401));
    await controller.restore();
    expect(controller.snapshot().phase).toBe('anonymous');
    expect(await storage.read()).toBeNull();
});
test('offline restoration retains secure credential but exposes no cached identity', async () => {
    const { transport, storage, controller } = setup();
    await storage.write('tokenA');
    transport.mockRejectedValue(new Error('offline'));
    await controller.restore();
    expect(controller.snapshot()).toMatchObject({
        phase: 'offline',
        account: null,
        organization: null,
    });
    expect(await storage.read()).toBe('tokenA');
});
test('late restoration cannot reauthenticate after logout', async () => {
    const { transport, storage, controller } = setup();
    await storage.write('tokenA');
    const wait = deferred<Response>();
    transport.mockReturnValue(wait.promise);
    const restore = controller.restore();
    await Promise.resolve();
    await Promise.resolve();
    await controller.clear();
    wait.resolve(response({ data: { id: 'A' } }));
    await restore;
    expect(controller.snapshot().phase).toBe('anonymous');
});
test('late 401 for A cannot erase B token', async () => {
    const { transport, api, storage, controller } = setup();
    await storage.write('A');
    api.client.token = 'A';
    const wait = deferred<Response>();
    transport.mockReturnValue(wait.promise);
    const request = api.account();
    api.client.token = 'B';
    await storage.write('B');
    wait.resolve(response({}, 401));
    await expect(request).rejects.toMatchObject({ kind: 'expired' });
    expect(api.client.token).toBe('B');
    expect(await storage.read()).toBe('B');
    expect(controller.snapshot().account).toBeNull();
});
test('member links validate UUIDs, route and fragment-only single-use secrets', () => {
    const secret = 'a'.repeat(64);
    expect(
        parseLink(`gtstudio://activate#email=a%40example.test&token=${secret}`),
    ).toEqual({ kind: 'activate', email: 'a@example.test', token: secret });
    expect(parseLink(`gtstudio://session/${org}/${id}`)).toEqual({
        kind: 'session',
        organization: org,
        id,
    });
    for (const bad of [
        'https://evil.test',
        'gtstudio://admin/x',
        'gtstudio://session/../staff',
        `gtstudio://reset?email=a%40b.test&token=${secret}`,
        `gtstudio://activate#email=a%40b.test&token=${secret}&token=${secret}`,
        'x'.repeat(2049),
    ])
        expect(parseLink(bad)).toBeNull();
});
test('push disabled boundary does not request permission or fake registration', async () => {
    const provider = { permission: jest.fn(), token: jest.fn() };
    const lifecycle = new DeviceLifecycle(provider, null);
    expect(await lifecycle.enable()).toBe('disabled');
    expect(provider.permission).not.toHaveBeenCalled();
});
test('push rotation revokes prior token and logout revokes current token', async () => {
    const provider = {
        permission: jest.fn(async () => 'granted' as const),
        token: jest
            .fn()
            .mockResolvedValueOnce('one')
            .mockResolvedValueOnce('two'),
    };
    const registry = {
        register: jest.fn(async () => {}),
        revoke: jest.fn(async () => {}),
    };
    const lifecycle = new DeviceLifecycle(provider, registry);
    await lifecycle.enable();
    await lifecycle.enable();
    await lifecycle.clear();
    expect(registry.register.mock.calls).toEqual([['one'], ['two']]);
    expect(registry.revoke.mock.calls).toEqual([['one'], ['two']]);
});
test('logout during push registration revokes the late registration', async () => {
    const wait = deferred<void>();
    const registry = {
        register: jest.fn(() => wait.promise),
        revoke: jest.fn(async () => {}),
    };
    const lifecycle = new DeviceLifecycle(
        { permission: async () => 'granted', token: async () => 'late' },
        registry,
    );
    const enabling = lifecycle.enable();
    for (let i = 0; i < 20 && registry.register.mock.calls.length === 0; i++)
        await Promise.resolve();
    expect(registry.register).toHaveBeenCalledWith('late');
    await lifecycle.clear();
    wait.resolve();
    expect(await enabling).toBe('disabled');
    expect(registry.revoke).toHaveBeenCalledWith('late');
});
test('query keys isolate account and studio and cache stays bounded', async () => {
    const cache = createCache();
    expect(memberKey('A', org, 'sessions')).not.toEqual(
        memberKey('B', org, 'sessions'),
    );
    for (let n = 0; n < 100; n++)
        cache.setQueryData(memberKey('A', org, 'page', n), n);
    expect(cache.getQueryCache().getAll().length).toBeLessThanOrEqual(40);
    cache.clear();
});
test('complete MK/EN dictionary and organization-local DST display', () => {
    expect(Object.keys(mk).sort()).toEqual(Object.keys(en).sort());
    expect(Object.values(mk).every(Boolean)).toBe(true);
    expect(dateTime('2026-03-29T01:30:00Z', 'Europe/Skopje', 'en')).toContain(
        '03:30',
    );
    expect(wallTime(1080)).toBe('18:00');
    expect(money(12500, 'MKD', 'en')).toContain('125');
});

test('403 during restore resolves to safe anonymous state instead of an endless spinner', async () => {
    const { transport, storage, controller } = setup();
    await storage.write('restricted');
    transport.mockResolvedValue(response({}, 403));
    await controller.restore();
    expect(controller.snapshot().phase).toBe('anonymous');
    expect(await storage.read()).toBeNull();
});

test('leaving a studio clears private data and invalidates a pending selection', async () => {
    const { transport, storage, controller, cache } = setup();
    await storage.write('A');
    transport.mockResolvedValue(
        response({
            data: { id: 'A', name: 'Member', email: 'a@example.test' },
        }),
    );
    await controller.restore();
    const wait = deferred<Response>();
    transport.mockReturnValue(wait.promise);
    const selecting = controller.select({
        id: org,
        name: 'Studio',
        timezone: 'Europe/Skopje',
        locale: 'mk',
        currency: 'MKD',
    });
    cache.setQueryData(memberKey('A', org, 'payments'), { private: 'old' });
    controller.leaveOrganization();
    wait.resolve(response({ data: {} }));
    await selecting;
    expect(controller.snapshot().organization).toBeNull();
    expect(controller.snapshot().account?.id).toBe('A');
    expect(cache.getQueryCache().getAll()).toHaveLength(0);
});

test('push navigation accepts only authorized domain route shapes, never account capabilities or URLs', () => {
    expect(parsePushLink('https://example.test/notice')).toBeNull();
    expect(parsePushLink({ url: 'gtstudio://notification' })).toBeNull();
    expect(
        parsePushLink(
            'gtstudio://reset#email=member@example.test&token=' +
                'a'.repeat(64),
        ),
    ).toBeNull();
    const org = '01900000-0000-7000-8000-000000000002';
    const id = '01900000-0000-7000-8000-000000000004';
    expect(parsePushLink(`gtstudio://notification/${org}/${id}`)).toEqual({
        kind: 'notification',
        organization: org,
        id,
    });
});
