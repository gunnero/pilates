import { shareReport } from '../api/report-export';
import { OperationsClient } from '../api/operations';
import * as Sharing from 'expo-sharing';
const mockWrite = jest.fn();
const mockDelete = jest.fn();
jest.mock('expo-file-system', () => ({
    Paths: { cache: 'private-cache' },
    Directory: class {
        exists = false;
        create() {}
        delete() {}
    },
    File: class {
        uri = 'private-cache/report.xlsx';
        exists = true;
        create() {}
        write = mockWrite;
        delete = mockDelete;
    },
}));
jest.mock('expo-sharing', () => ({
    isAvailableAsync: jest.fn(async () => true),
    shareAsync: jest.fn(async () => {}),
}));
beforeEach(() => {
    jest.clearAllMocks();
    globalThis.fetch = jest.fn(
        async () =>
            ({
                ok: true,
                status: 200,
                arrayBuffer: async () => new Uint8Array([80, 75, 3, 4]).buffer,
            }) as Response,
    );
});
it('uses an authenticated request and removes a private file after sharing', async () => {
    const client = new OperationsClient('https://example.test');
    client.setToken('secret-fixture');
    await shareReport(
        client,
        '/reports?format=xlsx',
        'xlsx',
        new AbortController().signal,
    );
    expect(fetch).toHaveBeenCalledWith(
        'https://example.test/reports?format=xlsx',
        expect.objectContaining({
            headers: expect.objectContaining({
                Authorization: 'Bearer secret-fixture',
            }),
        }),
    );
    expect(mockWrite).toHaveBeenCalled();
    expect(Sharing.shareAsync).toHaveBeenCalledWith(
        'private-cache/report.xlsx',
        expect.anything(),
    );
    expect(mockDelete).toHaveBeenCalled();
});
it('does not save or share after context invalidation', async () => {
    const client = new OperationsClient('https://example.test');
    globalThis.fetch = jest.fn(async () => {
        client.invalidate();
        return {
            ok: true,
            status: 200,
            arrayBuffer: async () => new ArrayBuffer(4),
        } as Response;
    });
    await shareReport(
        client,
        '/reports?format=xlsx',
        'xlsx',
        new AbortController().signal,
    );
    expect(mockWrite).not.toHaveBeenCalled();
    expect(Sharing.shareAsync).not.toHaveBeenCalled();
});
it('cleans files when OS sharing fails', async () => {
    jest.mocked(Sharing.shareAsync).mockRejectedValueOnce(
        new Error('share unavailable'),
    );
    await expect(
        shareReport(
            new OperationsClient('https://example.test'),
            '/reports?format=csv',
            'csv',
            new AbortController().signal,
        ),
    ).rejects.toThrow('share unavailable');
    expect(mockDelete).toHaveBeenCalled();
});
