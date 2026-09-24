export type ErrorKind =
    | 'configuration'
    | 'network'
    | 'expired'
    | 'forbidden'
    | 'missing'
    | 'validation'
    | 'conflict'
    | 'limited'
    | 'server';
export class ApiError extends Error {
    constructor(public readonly kind: ErrorKind) {
        super(kind);
    }
}
export function apiOrigin(
    value: string | undefined,
    development: boolean,
    localHttp: boolean,
): string {
    try {
        const url = new URL(value ?? '');
        const local =
            /^(localhost|127\.0\.0\.1|10\.0\.2\.2|192\.168\.\d{1,3}\.\d{1,3}|10\.\d{1,3}\.\d{1,3}\.\d{1,3})$/.test(
                url.hostname,
            );
        if (
            url.username ||
            url.password ||
            url.search ||
            url.hash ||
            url.pathname !== '/' ||
            (url.protocol !== 'https:' &&
                !(
                    development &&
                    localHttp &&
                    local &&
                    url.protocol === 'http:'
                ))
        )
            throw new Error();
        return url.origin;
    } catch {
        throw new ApiError('configuration');
    }
}
export class ApiClient {
    token: string | null = null;
    locale: 'mk' | 'en' = 'mk';
    setLocale(locale: 'mk' | 'en') {
        this.locale = locale;
    }
    onDenied: (kind: 'expired' | 'forbidden', token: string | null) => void =
        () => {};
    constructor(
        readonly origin: string,
        private transport: typeof fetch = fetch,
        private timeout = 15000,
    ) {}
    async request<T>(
        path: string,
        method = 'GET',
        body?: unknown,
        externalSignal?: AbortSignal,
    ): Promise<T> {
        if (!path.startsWith('/api/v1/') || path.includes('..'))
            throw new ApiError('configuration');
        const controller = new AbortController();
        const abort = () => controller.abort();
        externalSignal?.addEventListener('abort', abort);
        if (externalSignal?.aborted) controller.abort();
        const timer = setTimeout(abort, this.timeout);
        const token = this.token;
        try {
            const response = await this.transport(this.origin + path, {
                method,
                signal: controller.signal,
                credentials: 'omit',
                redirect: 'error',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'Accept-Language': this.locale,
                    ...(token ? { Authorization: `Bearer ${token}` } : {}),
                },
                ...(body === undefined ? {} : { body: JSON.stringify(body) }),
            });
            if (!response.ok) {
                const kind: ErrorKind =
                    (
                        {
                            401: 'expired',
                            403: 'forbidden',
                            404: 'missing',
                            409: 'conflict',
                            422: 'validation',
                            429: 'limited',
                        } as Record<number, ErrorKind>
                    )[response.status] ?? 'server';
                if (kind === 'expired' || kind === 'forbidden')
                    this.onDenied(kind, token);
                throw new ApiError(kind);
            }
            return response.status === 204
                ? (undefined as T)
                : ((await response.json()) as T);
        } catch (error) {
            if (error instanceof ApiError) throw error;
            throw new ApiError('network');
        } finally {
            clearTimeout(timer);
            externalSignal?.removeEventListener('abort', abort);
        }
    }
}
export function uuid(value: string): string {
    if (
        !/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i.test(
            value,
        )
    )
        throw new ApiError('validation');
    return value;
}
export function pageQuery(page = 1): string {
    if (!Number.isInteger(page) || page < 1 || page > 10000)
        throw new ApiError('validation');
    return `per_page=25&page=${page}`;
}
