import { ApiClient } from './client';
/** An instance is owned by one staff screen tree, never shared with member access. */
export class OperationsClient extends ApiClient {
    generation = 0;
    private locked = false;
    setDenied(handler: ApiClient['onDenied']) {
        this.onDenied = handler;
    }
    invalidate() {
        this.generation++;
    }
    begin() {
        if (this.locked) return false;
        this.locked = true;
        return true;
    }
    end() {
        this.locked = false;
    }
}
