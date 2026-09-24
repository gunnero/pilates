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
    constructor(
        private provider: PushProvider,
        private registry: DeviceRegistry | null,
    ) {}
    async enable(): Promise<'disabled' | 'denied' | 'registered'> {
        if (!this.registry) return 'disabled';
        const generation = ++this.generation;
        if ((await this.provider.permission()) !== 'granted') return 'denied';
        const token = await this.provider.token();
        if (generation !== this.generation) return 'disabled';
        if (this.current === token) return 'registered';
        if (this.current) await this.registry.revoke(this.current);
        await this.registry.register(token);
        if (generation !== this.generation) {
            await this.registry.revoke(token);
            return 'disabled';
        }
        this.current = token;
        return 'registered';
    }
    async clear() {
        this.generation++;
        const token = this.current;
        this.current = null;
        if (token && this.registry) await this.registry.revoke(token);
    }
}
