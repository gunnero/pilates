import { useTranslation } from 'react-i18next';
export function LanguagePicker() {
    const { t, i18n } = useTranslation();
    return (
        <label className="language">
            <span className="sr-only">{t('language')}</span>
            <select
                value={i18n.language}
                onChange={(event) =>
                    void i18n.changeLanguage(event.target.value)
                }
            >
                <option value="mk">{t('mk')}</option>
                <option value="en">{t('en')}</option>
            </select>
        </label>
    );
}
