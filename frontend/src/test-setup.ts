import '@testing-library/jest-dom';

// Radix UI uses ResizeObserver for popover/tooltip positioning
globalThis.ResizeObserver = class ResizeObserver {
  observe() {}
  unobserve() {}
  disconnect() {}
};

// Radix UI uses window.matchMedia for responsive behavior
Object.defineProperty(window, 'matchMedia', {
  writable: true,
  value: (query: string) => ({
    matches: false,
    media: query,
    onchange: null,
    addListener: () => {},
    removeListener: () => {},
    addEventListener: () => {},
    removeEventListener: () => {},
    dispatchEvent: () => false,
  }),
});

// Radix Popper / floating-ui needs a basic getComputedStyle that returns sizes
const _getComputedStyle = window.getComputedStyle;
window.getComputedStyle = (el, pseudo) => {
  const style = _getComputedStyle(el, pseudo);
  return style;
};

// jsdom does not implement scrollIntoView at all (not a partial stub — the
// property is simply absent, even though the DOM lib types always declare it),
// so any component that calls it (e.g. to keep a chat-style view scrolled to
// its latest message) throws in tests otherwise.
Element.prototype.scrollIntoView = function scrollIntoView() {};

// jsdom does not implement the Web Speech API's speechSynthesis at all (used
// by the AI assistant's optional voice feature — TASK-ECOS-V1.1-FINAL-AI-
// ASSISTANT-PERSONALIZED-COMPANION-046). A minimal stub so components that
// merely check for/read available voices don't throw in tests that don't
// specifically exercise voice behavior; tests that DO exercise it mock
// `isSpeechSynthesisSupported`/`isSpeechRecognitionSupported` directly instead
// of relying on this stub's exact behavior.
if (typeof window !== 'undefined' && !('speechSynthesis' in window)) {
  Object.defineProperty(window, 'speechSynthesis', {
    writable: true,
    value: {
      getVoices: () => [],
      speak: () => {},
      cancel: () => {},
      addEventListener: () => {},
      removeEventListener: () => {},
    },
  });
}
