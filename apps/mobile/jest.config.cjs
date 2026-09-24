module.exports = {
    preset: 'jest-expo',
    testMatch: ['**/src/__tests__/**/*.test.[jt]s?(x)'],
    transformIgnorePatterns: [
        'node_modules/(?!((?:\\.pnpm/)?(@react-native|react-native|expo|@expo|@react-navigation|react-native-safe-area-context|react-native-screens)))',
    ],
};
