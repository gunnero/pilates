import { useRef, useState } from 'react';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { useMember } from '../auth/context';
import { memberKey } from './cache';
import { ApiError } from '../api/client';
export function useMemberQuery<T>(
    parts: (string | number)[],
    fetcher: (signal: AbortSignal) => Promise<T>,
) {
    const { account, organization } = useMember();
    return useQuery({
        queryKey: memberKey(account.id, organization.id, ...parts),
        queryFn: ({ signal }) => fetcher(signal),
    });
}
export function useAction() {
    const { account, organization } = useMember();
    const cache = useQueryClient();
    const lock = useRef(false);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<unknown>(null);
    const [done, setDone] = useState(false);
    async function run(action: () => Promise<unknown>) {
        if (lock.current) return;
        lock.current = true;
        setBusy(true);
        setError(null);
        setDone(false);
        try {
            await action();
            setDone(true);
        } catch (e) {
            setError(e);
        } finally {
            await cache.invalidateQueries({
                queryKey: memberKey(account.id, organization.id),
            });
            lock.current = false;
            setBusy(false);
        }
    }
    return {
        run,
        busy,
        error,
        done,
        ambiguous: error instanceof ApiError && error.kind === 'network',
    };
}
