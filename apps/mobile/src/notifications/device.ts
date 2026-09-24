export interface PushProvider {
    permission(): Promise<'granted' | 'denied'>;
    token(): Promise<string>;
}
export interface DeviceRegistry {
    register(token: string): Promise<void>;
    revoke(token: string): Promise<void>;
}
export class DeviceLifecycle {
    private current: string | null = null;
    private generation = 0;
    private pending: Promise<unknown> = Promise.resolve();
    constructor(
        private provider: PushProvider,
        private registry: DeviceRegistry | null,
    ) {}
    configure(registry: DeviceRegistry | null) {
        void this.clear().catch(() => undefined);
        this.registry = registry;
    }
    enable(): Promise<'disabled' | 'denied' | 'registered'> {
        const generation = this.generation;
        const operation = this.pending
            .catch(() => undefined)
            .then(() =>
                generation === this.generation
                    ? this.enableOnce()
                    : ('disabled' as const),
            );
        this.pending = operation;
        return operation;
    }
    private async enableOnce(): Promise<'disabled' | 'denied' | 'registered'> {
        if (!this.registry) return 'disabled';
        const registry = this.registry;
        const generation = this.generation;
        if ((await this.provider.permission()) !== 'granted') return 'denied';
        const token = await this.provider.token();
        if (generation !== this.generation) return 'disabled';
        if (this.current === token) return 'registered';
        if (this.current) await registry.revoke(this.current);
        await registry.register(token);
        if (generation !== this.generation) {
            await registry.revoke(token);
            return 'disabled';
        }
        this.current = token;
        return 'registered';
    }
    async clear() {
        this.generation++;
        const registry = this.registry;
        const token = this.current;
        this.current = null;
        if (token && registry) await registry.revoke(token);
    }
}
