import i18n from 'i18next';
import { initReactI18next } from 'react-i18next';
import mk from '../locales/mk.json';
import en from '../locales/en.json';
void i18n.use(initReactI18next).init({
    resources: { mk: { translation: mk }, en: { translation: en } },
    lng: 'mk',
    fallbackLng: 'en',
    supportedLngs: ['mk', 'en'],
    interpolation: { escapeValue: false },
});
i18n.on('languageChanged', (language) => {
    document.documentElement.lang = language;
});
export default i18n;
