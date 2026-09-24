import { createContext, useContext, useState, type ReactNode } from 'react';
import { en, mk, type TextKey } from './strings';
const LocaleContext = createContext({
    locale: 'mk' as 'mk' | 'en',
    setLocale: (locale: 'mk' | 'en') => {
        void locale;
    },
    t: (key: TextKey): string => mk[key],
});
export function LocaleProvider({ children }: { children: ReactNode }) {
    const [locale, setLocale] = useState<'mk' | 'en'>('mk');
    return (
        <LocaleContext.Provider
            value={{
                locale,
                setLocale,
                t: (key) => (locale === 'mk' ? mk : en)[key],
            }}
        >
            {children}
        </LocaleContext.Provider>
    );
}
export const useLocale = () => useContext(LocaleContext);
export function statusKey(status: string): TextKey {
    if (status === 'waiting') return 'waiting_status';
    if (status === 'upcoming') return 'upcoming_status';
    return status in en ? (status as TextKey) : 'other';
}
