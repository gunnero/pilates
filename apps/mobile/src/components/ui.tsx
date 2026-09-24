import type { ReactNode } from 'react';
import {
    ActivityIndicator,
    KeyboardAvoidingView,
    Platform,
    Pressable,
    RefreshControl,
    ScrollView,
    StyleSheet,
    Text,
    TextInput,
    View,
} from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import { useLocale } from '../locales/context';
import { ApiError } from '../api/client';
export const styles = StyleSheet.create({
    screen: { flex: 1, backgroundColor: '#f5f4ef' },
    content: { padding: 20, gap: 16, paddingBottom: 40 },
    title: { fontSize: 28, fontWeight: '700', color: '#173a38' },
    text: { fontSize: 17, lineHeight: 25, color: '#183a38' },
    card: {
        backgroundColor: '#fff',
        borderRadius: 16,
        padding: 18,
        gap: 10,
        borderWidth: 1,
        borderColor: '#d7dfd9',
    },
    button: {
        minHeight: 48,
        paddingVertical: 13,
        paddingHorizontal: 18,
        borderRadius: 12,
        backgroundColor: '#175b51',
        alignItems: 'center',
        justifyContent: 'center',
    },
    buttonText: { fontSize: 16, fontWeight: '600', color: '#fff' },
    disabled: { opacity: 0.5 },
    input: {
        minHeight: 50,
        borderWidth: 1,
        borderColor: '#627b73',
        borderRadius: 10,
        padding: 12,
        fontSize: 17,
        color: '#183a38',
        backgroundColor: '#fff',
    },
    row: { flexDirection: 'row', flexWrap: 'wrap', gap: 10 },
    error: { color: '#8e2632', fontSize: 16, lineHeight: 24 },
});
export function Body({ children }: { children: ReactNode }) {
    return <Text style={styles.text}>{children}</Text>;
}
export function Heading({ children }: { children: ReactNode }) {
    return (
        <Text accessibilityRole="header" style={styles.title}>
            {children}
        </Text>
    );
}
export function Card({ children }: { children: ReactNode }) {
    return <View style={styles.card}>{children}</View>;
}
export function Button({
    label,
    onPress,
    disabled = false,
}: {
    label: string;
    onPress: () => void;
    disabled?: boolean;
}) {
    return (
        <Pressable
            accessibilityRole="button"
            accessibilityLabel={label}
            accessibilityState={{ disabled }}
            disabled={disabled}
            onPress={onPress}
            style={[styles.button, disabled && styles.disabled]}
        >
            <Text style={styles.buttonText}>{label}</Text>
        </Pressable>
    );
}
export function Field({
    label,
    value,
    onChange,
    secret = false,
    email = false,
}: {
    label: string;
    value: string;
    onChange: (value: string) => void;
    secret?: boolean;
    email?: boolean;
}) {
    return (
        <View style={{ gap: 6 }}>
            <Body>{label}</Body>
            <TextInput
                accessibilityLabel={label}
                value={value}
                onChangeText={onChange}
                secureTextEntry={secret}
                keyboardType={email ? 'email-address' : 'default'}
                autoCapitalize="none"
                autoCorrect={false}
                maxLength={secret ? 128 : 254}
                style={styles.input}
            />
        </View>
    );
}
export function Screen({
    children,
    refresh,
    refreshing = false,
    headerless = false,
}: {
    children: ReactNode;
    refresh?: () => void;
    refreshing?: boolean;
    headerless?: boolean;
}) {
    return (
        <SafeAreaView
            style={styles.screen}
            edges={
                headerless
                    ? ['top', 'bottom', 'left', 'right']
                    : ['bottom', 'left', 'right']
            }
        >
            <KeyboardAvoidingView
                style={{ flex: 1 }}
                behavior={Platform.OS === 'ios' ? 'padding' : undefined}
            >
                <ScrollView
                    contentContainerStyle={styles.content}
                    keyboardShouldPersistTaps="handled"
                    refreshControl={
                        refresh ? (
                            <RefreshControl
                                refreshing={refreshing}
                                onRefresh={refresh}
                            />
                        ) : undefined
                    }
                >
                    {children}
                </ScrollView>
            </KeyboardAvoidingView>
        </SafeAreaView>
    );
}
export function ErrorMessage({ error }: { error: unknown }) {
    const { t } = useLocale();
    if (!error) return null;
    const kind = error instanceof ApiError ? error.kind : 'server';
    return (
        <Text
            accessibilityRole="alert"
            accessibilityLiveRegion="polite"
            style={styles.error}
        >
            {t(kind === 'expired' ? 'sessionExpired' : kind)}
        </Text>
    );
}
export function Loading() {
    const { t } = useLocale();
    return (
        <ActivityIndicator
            accessibilityLabel={t('loading')}
            size="large"
            color="#175b51"
        />
    );
}
export function Pager({
    page,
    last,
    setPage,
}: {
    page: number;
    last: number;
    setPage: (page: number) => void;
}) {
    const { t } = useLocale();
    return (
        <View style={styles.row}>
            <Button
                label={t('previous')}
                disabled={page <= 1}
                onPress={() => setPage(page - 1)}
            />
            <Body>
                {t('page')} {page} / {last}
            </Body>
            <Button
                label={t('next')}
                disabled={page >= last || page >= 10000}
                onPress={() => setPage(page + 1)}
            />
        </View>
    );
}
