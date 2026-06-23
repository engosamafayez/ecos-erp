import { useEffect, useState, type ReactNode } from 'react';
import { I18nextProvider } from 'react-i18next';

import i18n from '@/i18n/i18n';
import { LanguageContext, type Language } from '@/providers/language-context';

const STORAGE_KEY = 'language';
const SUPPORTED: Language[] = ['en', 'ar'];
const DIR: Record<Language, 'ltr' | 'rtl'> = { en: 'ltr', ar: 'rtl' };

function getInitialLanguage(): Language {
  const stored = localStorage.getItem(STORAGE_KEY) as Language | null;
  return stored !== null && SUPPORTED.includes(stored) ? stored : 'en';
}

function applyToDocument(lang: Language): void {
  document.documentElement.lang = lang;
  document.documentElement.dir = DIR[lang];
}

export function LanguageProvider({ children }: { children: ReactNode }) {
  const [language, setLanguageState] = useState<Language>(getInitialLanguage);

  useEffect(() => {
    applyToDocument(language);
  }, [language]);

  const setLanguage = (lang: Language): void => {
    localStorage.setItem(STORAGE_KEY, lang);
    void i18n.changeLanguage(lang);
    setLanguageState(lang);
  };

  return (
    <LanguageContext.Provider value={{ language, setLanguage }}>
      <I18nextProvider i18n={i18n}>{children}</I18nextProvider>
    </LanguageContext.Provider>
  );
}
