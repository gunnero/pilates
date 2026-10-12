import { Directory, File, Paths } from 'expo-file-system';
import * as Sharing from 'expo-sharing';
import { ApiError } from './client';
import type { OperationsClient } from './operations';

/** Private cache files exist only while preparing/sharing; no credentials enter URLs. */
export async function shareReport(
    client: OperationsClient,
    path: string,
    format: 'csv' | 'xlsx',
    signal: AbortSignal,
) {
    const generation = client.generation;
    const token = client.token;
    const controller = new AbortController();
    const abort = () => controller.abort();
    signal.addEventListener('abort', abort, { once: true });
    if (signal.aborted) abort();
    const timeout = setTimeout(abort, 30000);
    let response: Response;
    let bytes: Uint8Array;
    try {
        response = await fetch(client.origin + path, {
            headers: {
                Authorization: `Bearer ${token}`,
                Accept: 'application/octet-stream',
                'Accept-Language': client.locale,
            },
            signal: controller.signal,
        });
        if (response.status === 401 || response.status === 403)
            client.onDenied(
                response.status === 401 ? 'expired' : 'forbidden',
                token,
            );
        if (!response.ok)
            throw new ApiError(
                response.status === 422
                    ? 'validation'
                    : response.status === 403
                      ? 'forbidden'
                      : 'server',
            );
        bytes = new Uint8Array(await response.arrayBuffer());
    } finally {
        clearTimeout(timeout);
        signal.removeEventListener('abort', abort);
    }
    if (bytes.length > 26214400) throw new ApiError('limited');
    if (
        signal.aborted ||
        generation !== client.generation ||
        token !== client.token
    )
        return;
    const directory = new Directory(Paths.cache, 'gt-report-exports');
    directory.create({ idempotent: true, intermediates: true });
    const file = new File(directory, `report-${Date.now()}.${format}`);
    try {
        file.create({ overwrite: true });
        file.write(bytes);
        if (signal.aborted || generation !== client.generation) return;
        if (!(await Sharing.isAvailableAsync()))
            throw new ApiError('configuration');
        if (signal.aborted || generation !== client.generation) return;
        await Sharing.shareAsync(file.uri, {
            mimeType:
                format === 'csv'
                    ? 'text/csv'
                    : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            UTI:
                format === 'csv'
                    ? 'public.comma-separated-values-text'
                    : 'org.openxmlformats.spreadsheetml.sheet',
        });
    } finally {
        if (file.exists) file.delete();
    }
}
export function clearReportFiles() {
    const directory = new Directory(Paths.cache, 'gt-report-exports');
    if (directory.exists) directory.delete();
}
