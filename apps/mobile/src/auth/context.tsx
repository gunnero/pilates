import {
    createContext,
    useContext,
    useSyncExternalStore,
    type ReactNode,
} from 'react';
import type { SessionController } from './session';
const Context = createContext<SessionController | null>(null);
export function SessionProvider({
    controller,
    children,
}: {
    controller: SessionController;
    children: ReactNode;
}) {
    return <Context.Provider value={controller}>{children}</Context.Provider>;
}
export function useSession() {
    const controller = useContext(Context);
    if (!controller) throw new Error('Session provider required');
    const state = useSyncExternalStore(
        controller.subscribe,
        controller.snapshot,
    );
    return { controller, ...state };
}
export function useMember() {
    const session = useSession();
    if (!session.account || !session.organization)
        throw new Error('Member context required');
    return {
        ...session,
        account: session.account,
        organization: session.organization,
        api: session.controller.api,
    };
}
