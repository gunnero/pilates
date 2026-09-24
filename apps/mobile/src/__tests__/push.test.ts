jest.mock('expo-notifications', () => ({
    getPermissionsAsync: jest.fn(async () => ({ granted: false })),
    requestPermissionsAsync: jest.fn(async () => ({ granted: true })),
    getExpoPushTokenAsync: jest.fn(async () => ({
        data: 'ExpoPushToken[test_native_push]',
    })),
    setNotificationChannelAsync: jest.fn(async () => {}),
    AndroidImportance: { DEFAULT: 3 },
    addPushTokenListener: jest.fn(() => ({ remove: jest.fn() })),
}));
jest.mock('expo-secure-store', () => ({
    getItemAsync: jest.fn(async () => '01900000-0000-7000-8000-000000000001'),
    setItemAsync: jest.fn(async () => {}),
}));
jest.mock('expo-crypto', () => ({
    randomUUID: () => '01900000-0000-7000-8000-000000000001',
}));

const org = '01900000-0000-7000-8000-000000000002';
const other = '01900000-0000-7000-8000-000000000003';
const registration = '01900000-0000-7000-8000-000000000004';
function setup(configured = true) {
    jest.resetModules();
    if (configured) process.env.EXPO_PUBLIC_EAS_PROJECT_ID = 'fixture-project';
    else delete process.env.EXPO_PUBLIC_EAS_PROJECT_ID;
    const transport = jest.fn(async () => ({
        ok: true,
        status: 201,
        json: async () => ({ data: { id: registration } }),
    }));
    globalThis.fetch = transport as unknown as typeof fetch;
    const { ApiClient } = jest.requireActual(
        '../api/client',
    ) as typeof import('../api/client');
    const { MemberApi } = jest.requireActual(
        '../api/member',
    ) as typeof import('../api/member');
    const native = jest.requireActual(
        '../notifications/native',
    ) as typeof import('../notifications/native');
    const notifications = jest.requireMock(
        'expo-notifications',
    ) as typeof import('expo-notifications');
    const api = new MemberApi(new ApiClient('https://example.test'));
    api.client.token = 'fixture-login';
    return { transport, native, notifications, api };
}
afterEach(() => {
    delete process.env.EXPO_PUBLIC_EAS_PROJECT_ID;
});
test('unconfigured push never requests permission or a token', async () => {
    const { native, notifications, api, transport } = setup(false);
    native.bindDevice(api, org);
    expect(await native.device.enable()).toBe('disabled');
    expect(notifications.requestPermissionsAsync).not.toHaveBeenCalled();
    expect(transport).not.toHaveBeenCalled();
});
test('permission denial performs no registration', async () => {
    const { native, notifications, api, transport } = setup();
    jest.mocked(notifications.requestPermissionsAsync).mockResolvedValue({
        granted: false,
    } as never);
    const cleanup = native.bindDevice(api, org);
    expect(await native.device.enable()).toBe('denied');
    expect(transport).not.toHaveBeenCalled();
    cleanup();
});
test('Expo project token is registered once and cleanup retains its original account credential', async () => {
    const { native, notifications, api, transport } = setup();
    const cleanup = native.bindDevice(api, org);
    expect(await native.device.enable()).toBe('registered');
    expect(await native.device.enable()).toBe('registered');
    expect(notifications.getExpoPushTokenAsync).toHaveBeenCalledWith({
        projectId: 'fixture-project',
    });
    expect(transport).toHaveBeenCalledTimes(1);
    api.client.token = 'different-account';
    await native.device.clear();
    const calls = transport.mock.calls as unknown as [string, RequestInit][];
    expect(calls[0][0]).toBe(
        `https://example.test/api/v1/member/organizations/${org}/devices`,
    );
    expect(JSON.parse(calls[0][1].body as string)).toMatchObject({
        token: 'ExpoPushToken[test_native_push]',
        installation_id: '01900000-0000-7000-8000-000000000001',
    });
    expect(calls[1][1].method).toBe('DELETE');
    expect(calls[1][1].headers).toMatchObject({
        Authorization: 'Bearer fixture-login',
    });
    cleanup();
});
test('token rotation revokes the prior registration before registering the new token', async () => {
    const { native, notifications, api, transport } = setup();
    const cleanup = native.bindDevice(api, org);
    await native.device.enable();
    jest.mocked(notifications.getExpoPushTokenAsync).mockResolvedValue({
        type: 'expo',
        data: 'ExpoPushToken[rotated_native_push]',
    });
    await native.device.enable();
    const calls = transport.mock.calls as unknown as [string, RequestInit][];
    expect(calls.map((c) => c[1].method)).toEqual(['POST', 'DELETE', 'POST']);
    await native.device.clear();
    cleanup();
});
test('organization switching binds new registration to the selected studio', async () => {
    const { native, api, transport } = setup();
    const cleanup = native.bindDevice(api, org);
    await native.device.enable();
    cleanup();
    const next = native.bindDevice(api, other);
    await native.device.enable();
    const calls = transport.mock.calls as unknown as [string, RequestInit][];
    expect(
        calls.filter((c) => c[1].method === 'POST').map((c) => c[0]),
    ).toEqual([
        `https://example.test/api/v1/member/organizations/${org}/devices`,
        `https://example.test/api/v1/member/organizations/${other}/devices`,
    ]);
    await native.device.clear();
    next();
});
test('network failure never claims registration success and can be retried', async () => {
    const { native, api, transport } = setup();
    const cleanup = native.bindDevice(api, org);
    transport.mockRejectedValueOnce(new Error('offline'));
    await expect(native.device.enable()).rejects.toMatchObject({
        kind: 'network',
    });
    expect(await native.device.enable()).toBe('registered');
    await native.device.clear();
    cleanup();
});
