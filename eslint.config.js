import js from '@eslint/js';
import globals from 'globals';
import tseslint from 'typescript-eslint';
import hooks from 'eslint-plugin-react-hooks';
import refresh from 'eslint-plugin-react-refresh';
export default tseslint.config(
    {
        ignores: [
            'vendor/**',
            '**/node_modules/**',
            'apps/mobile/dist/**',
            'apps/mobile/.expo/**',
            'apps/mobile/coverage/**',
            'apps/mobile/android/**',
            'apps/mobile/ios/**',
            'public/build/**',
            '.local/**',
            'storage/**',
            'bootstrap/cache/**',
            'playwright-report/**',
            'test-results/**',
        ],
    },
    { files: ['**/*.cjs'], languageOptions: { globals: globals.node } },
    js.configs.recommended,
    ...tseslint.configs.recommended,
    {
        files: ['**/*.{ts,tsx}'],
        languageOptions: { globals: { ...globals.browser, ...globals.node } },
        plugins: { 'react-hooks': hooks, 'react-refresh': refresh },
        rules: {
            ...hooks.configs.recommended.rules,
            'react-refresh/only-export-components': [
                'error',
                { allowConstantExport: true },
            ],
        },
    },
    {
        files: ['apps/mobile/**/*.{ts,tsx}'],
        languageOptions: { globals: { ...globals.jest, __DEV__: 'readonly' } },
        rules: { 'react-refresh/only-export-components': 'off' },
    },
);
