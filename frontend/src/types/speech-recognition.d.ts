/**
 * TASK-ECOS-V1.1-FINAL-AI-ASSISTANT-PERSONALIZED-COMPANION-046 (CTO voice scope
 * override) — minimal ambient types for the Web Speech API's SpeechRecognition
 * interface. Not yet part of TypeScript's own lib.dom.d.ts (unlike
 * SpeechSynthesis/SpeechSynthesisUtterance, which are already standard and
 * typed) despite being implemented natively by Chromium-based browsers as
 * `webkitSpeechRecognition` — this file declares only the subset this app
 * actually uses, not the full spec.
 */

interface SpeechRecognitionResultItem {
  readonly transcript: string;
  readonly confidence: number;
}

interface SpeechRecognitionResultItemList {
  readonly length: number;
  item(index: number): SpeechRecognitionResultItem;
  [index: number]: SpeechRecognitionResultItem;
}

interface SpeechRecognitionResultList {
  readonly length: number;
  item(index: number): SpeechRecognitionResultItemList;
  [index: number]: SpeechRecognitionResultItemList & { isFinal: boolean };
}

interface SpeechRecognitionEvent extends Event {
  readonly resultIndex: number;
  readonly results: SpeechRecognitionResultList;
}

interface SpeechRecognitionErrorEvent extends Event {
  readonly error: string;
  readonly message: string;
}

interface SpeechRecognition extends EventTarget {
  lang: string;
  continuous: boolean;
  interimResults: boolean;
  maxAlternatives: number;
  onresult: ((this: SpeechRecognition, ev: SpeechRecognitionEvent) => unknown) | null;
  onerror: ((this: SpeechRecognition, ev: SpeechRecognitionErrorEvent) => unknown) | null;
  onend: ((this: SpeechRecognition, ev: Event) => unknown) | null;
  onstart: ((this: SpeechRecognition, ev: Event) => unknown) | null;
  start(): void;
  stop(): void;
  abort(): void;
}

interface Window {
  SpeechRecognition?: new () => SpeechRecognition;
  webkitSpeechRecognition?: new () => SpeechRecognition;
}
