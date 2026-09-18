import i18n from './i18n';
export class ApiError extends Error {
    constructor(
        public status: number,
        message: string,
        public errors?: Record<string, string[]>,
    ) {
        super(message);
    }
}
async function request<T>(path: string, options: RequestInit = {}): Promise<T> {
    const csrf = document.cookie
        .split('; ')
        .find((cookie) => cookie.startsWith('XSRF-TOKEN='))
        ?.slice(11);
    const response = await fetch(path, {
        ...options,
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'Accept-Language': i18n.language,
            ...(csrf ? { 'X-XSRF-TOKEN': decodeURIComponent(csrf) } : {}),
            ...options.headers,
        },
    });
    if (!response.ok) {
        const payload = (await response.json().catch(() => ({}))) as {
            message?: string;
            errors?: Record<string, string[]>;
        };
        throw new ApiError(
            response.status,
            payload.message ?? i18n.t('error'),
            payload.errors,
        );
    }
    if (response.status === 204) return undefined as T;
    return response.json() as Promise<T>;
}
export const api = {
    get: <T>(path: string, signal?: AbortSignal) =>
        request<T>(path, { signal }),
    async post<T = void>(path: string, data: unknown = {}): Promise<T> {
        await request<void>('/sanctum/csrf-cookie');
        return request<T>(path, { method: 'POST', body: JSON.stringify(data) });
    },
};
export type Profile = {
    id: string;
    name: string;
    organizations: { id: string; name: string }[];
};
export type Organization = {
    id: string;
    name: string;
    locale: string;
    timezone: string;
    currency: string;
};
