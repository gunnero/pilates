import { defineConfig } from 'vitest/config';
import laravel from 'laravel-vite-plugin';
import react from '@vitejs/plugin-react';
export default defineConfig({
    plugins: [
        laravel({ input: ['resources/js/main.tsx'], refresh: true }),
        react(),
    ],
    server: {
        host: '127.0.0.1',
        port: 5173,
        strictPort: true,
        cors: { origin: 'http://127.0.0.1:8010' },
    },
    test: {
        environment: 'jsdom',
        setupFiles: ['resources/js/test-setup.ts'],
        include: ['resources/js/**/*.test.{ts,tsx}'],
        restoreMocks: true,
    },
});
