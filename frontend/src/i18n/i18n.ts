import i18n from 'i18next';
import LanguageDetector from 'i18next-browser-languagedetector';
import { initReactI18next } from 'react-i18next';

import arCommon from '@/i18n/locales/ar/common.json';
import enCommon from '@/i18n/locales/en/common.json';

void i18n
  .use(LanguageDetector)
  .use(initReactI18next)
  .init({
    resources: {
      en: { common: enCommon },
      ar: { common: arCommon },
    },
    defaultNS: 'common',
    fallbackLng: 'en',
    supportedLngs: ['en', 'ar'],
    detection: {
      order: ['localStorage'],
      lookupLocalStorage: 'language',
      cacheUserLanguage: true,
    },
    interpolation: { escapeValue: false },
  });

export default i18n;
