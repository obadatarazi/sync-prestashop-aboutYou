import i18n from 'i18next'
import LanguageDetector from 'i18next-browser-languagedetector'
import { initReactI18next } from 'react-i18next'
import ar from '@/locales/ar.json'
import en from '@/locales/en.json'

void i18n
  .use(LanguageDetector)
  .use(initReactI18next)
  .init({
    supportedLngs: ['en', 'ar'],
    fallbackLng: 'en',
    lng: 'en',
    interpolation: { escapeValue: false },
    defaultNS: 'common',
    ns: ['common', 'nav', 'errors', 'products', 'orders', 'syncPage', 'auth', 'settingsPage', 'mappingsPage'],
    resources: {
      en,
      ar,
    },
  })

void i18n.on('languageChanged', (lng) => {
  const rtl = lng === 'ar'
  document.documentElement.dir = rtl ? 'rtl' : 'ltr'
  document.documentElement.lang = lng
})

if (i18n.isInitialized) {
  const current = i18n.resolvedLanguage ?? i18n.language
  const rtl = current === 'ar'
  document.documentElement.dir = rtl ? 'rtl' : 'ltr'
  document.documentElement.lang = current
}

export default i18n
