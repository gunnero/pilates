import { QueryClient } from '@tanstack/react-query';
export function createCache() {
    const cache = new QueryClient({
        defaultOptions: {
            queries: {
                retry: false,
                staleTime: 0,
                gcTime: 60000,
                refetchOnWindowFocus: true,
            },
            mutations: { retry: false },
        },
    });
    // Inactive entries are capped even during rapid pagination.
    cache.getQueryCache().subscribe(() => {
        const inactive = cache
            .getQueryCache()
            .getAll()
            .filter(
                (query) =>
                    query.getObserversCount() === 0 &&
                    query.state.fetchStatus === 'idle',
            );
        for (const query of inactive.slice(
            0,
            Math.max(0, inactive.length - 40),
        ))
            cache.removeQueries({ queryKey: query.queryKey, exact: true });
    });
    return cache;
}
export const memberKey = (
    account: string,
    organization: string,
    ...parts: (string | number)[]
) => ['member', account, organization, ...parts] as const;
