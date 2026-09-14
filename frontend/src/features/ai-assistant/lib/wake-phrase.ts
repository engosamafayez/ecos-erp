/**
 * TASK-ECOS-V1.1-FINAL-AI-ASSISTANT-PERSONALIZED-COMPANION-046 (CTO voice scope
 * override) §5 — pure, dependency-free wake-phrase matching so it is unit-
 * testable without a real SpeechRecognition implementation (unavailable in
 * jsdom). This function ONLY answers "was the assistant's name said" — it
 * never inspects the transcript for a business command, and its caller (see
 * use-assistant-voice.ts) only ever uses a match to flip listening state, never
 * to execute an action.
 */
export function matchesWakePhrase(transcript: string, assistantName: string): boolean {
  const normalizedName = assistantName.trim().toLowerCase();
  if (normalizedName === '') return false;

  const normalizedTranscript = transcript.trim().toLowerCase();
  if (normalizedTranscript === '') return false;

  return normalizedTranscript.includes(normalizedName);
}
