import { useEffect, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Mic, Send, Square, Trash2 } from 'lucide-react';

import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';

type RecorderState =
  | { phase: 'requesting' }
  | { phase: 'recording'; startedAt: number }
  | { phase: 'preview'; blob: Blob; url: string; durationSeconds: number }
  | { phase: 'error'; reason: 'denied' | 'unavailable' | 'unsupported' | 'failed' };

/** First MediaRecorder mime type this browser actually supports, or undefined to let it pick its own default. */
function pickMimeType(): string | undefined {
  if (typeof MediaRecorder === 'undefined' || !MediaRecorder.isTypeSupported) return undefined;
  return ['audio/webm', 'audio/mp4', 'audio/ogg'].find((candidate) => MediaRecorder.isTypeSupported(candidate));
}

/**
 * Record → Stop → Preview → Send/Discard, entirely client-side via the native MediaRecorder
 * API — there is no precedent for this anywhere else in the codebase (confirmed by repo-wide
 * search), so this owns its full failure-state handling rather than following an existing hook.
 * The parent (MessageComposer) owns the actual upload/send call and its own pending/error UI;
 * this component's job ends at handing back a Blob + duration.
 */
export function VoiceRecorder({ onCancel, onSend }: { onCancel: () => void; onSend: (blob: Blob, durationSeconds: number) => void }) {
  const { t } = useTranslation('collaboration');
  const [state, setState] = useState<RecorderState>({ phase: 'requesting' });
  const [elapsed, setElapsed] = useState(0);

  const recorderRef = useRef<MediaRecorder | null>(null);
  const streamRef = useRef<MediaStream | null>(null);
  const chunksRef = useRef<Blob[]>([]);
  const createdUrlRef = useRef<string | null>(null);

  useEffect(() => {
    if (typeof navigator === 'undefined' || !navigator.mediaDevices?.getUserMedia || typeof MediaRecorder === 'undefined') {
      // eslint-disable-next-line react-hooks/set-state-in-effect -- one-time capability check against a browser global, not derivable from props/state during render
      setState({ phase: 'error', reason: 'unsupported' });
      return;
    }

    let cancelled = false;

    navigator.mediaDevices
      .getUserMedia({ audio: true })
      .then((stream) => {
        if (cancelled) {
          stream.getTracks().forEach((track) => track.stop());
          return;
        }

        streamRef.current = stream;
        chunksRef.current = [];

        const mimeType = pickMimeType();
        const recorder = mimeType ? new MediaRecorder(stream, { mimeType }) : new MediaRecorder(stream);
        recorderRef.current = recorder;

        recorder.ondataavailable = (event) => {
          if (event.data.size > 0) chunksRef.current.push(event.data);
        };
        recorder.onerror = () => setState({ phase: 'error', reason: 'failed' });

        recorder.start();
        setState({ phase: 'recording', startedAt: Date.now() });
      })
      .catch((error: DOMException) => {
        if (cancelled) return;
        const reason = error.name === 'NotAllowedError' || error.name === 'PermissionDeniedError' ? 'denied'
          : error.name === 'NotFoundError' || error.name === 'DevicesNotFoundError' ? 'unavailable'
            : 'failed';
        setState({ phase: 'error', reason });
      });

    return () => {
      cancelled = true;
      if (recorderRef.current && recorderRef.current.state !== 'inactive') {
        recorderRef.current.stop();
      }
      streamRef.current?.getTracks().forEach((track) => track.stop());
      if (createdUrlRef.current) URL.revokeObjectURL(createdUrlRef.current);
    };
  }, []);

  useEffect(() => {
    if (state.phase !== 'recording') return;
    const interval = setInterval(() => setElapsed(Math.floor((Date.now() - state.startedAt) / 1000)), 250);
    return () => clearInterval(interval);
  }, [state]);

  function stop() {
    const recorder = recorderRef.current;
    if (!recorder || state.phase !== 'recording') return;

    const durationSeconds = Math.max(1, Math.round((Date.now() - state.startedAt) / 1000));

    recorder.onstop = () => {
      streamRef.current?.getTracks().forEach((track) => track.stop());
      const blob = new Blob(chunksRef.current, { type: recorder.mimeType || 'audio/webm' });
      const url = URL.createObjectURL(blob);
      createdUrlRef.current = url;
      setState({ phase: 'preview', blob, url, durationSeconds });
    };

    recorder.stop();
  }

  function discard() {
    if (createdUrlRef.current) {
      URL.revokeObjectURL(createdUrlRef.current);
      createdUrlRef.current = null;
    }
    onCancel();
  }

  function formatElapsed(totalSeconds: number): string {
    const m = Math.floor(totalSeconds / 60).toString().padStart(2, '0');
    const s = (totalSeconds % 60).toString().padStart(2, '0');
    return `${m}:${s}`;
  }

  if (state.phase === 'error') {
    const message = t(($) => $.voice[
      state.reason === 'denied' ? 'micDenied'
        : state.reason === 'unavailable' ? 'micUnavailable'
          : state.reason === 'unsupported' ? 'unsupported'
            : 'recordingFailed'
    ]);
    return (
      <div className="flex items-center gap-2 rounded-md border border-destructive/30 bg-destructive/5 px-3 py-2 text-sm text-destructive">
        <span className="flex-1">{message}</span>
        <Button type="button" variant="ghost" size="sm" onClick={onCancel}>
          {t(($) => $.voice.discard)}
        </Button>
      </div>
    );
  }

  if (state.phase === 'requesting') {
    return (
      <div className="flex items-center gap-2 rounded-md border px-3 py-2 text-sm text-muted-foreground">
        <Mic className="size-4 animate-pulse" aria-hidden />
        {t(($) => $.voice.start)}
      </div>
    );
  }

  if (state.phase === 'recording') {
    return (
      <div className="flex items-center gap-3 rounded-md border px-3 py-2">
        <span className={cn('size-2.5 shrink-0 rounded-full bg-red-500', 'animate-pulse')} aria-hidden />
        <span className="text-sm font-medium tabular-nums">{formatElapsed(elapsed)}</span>
        <span className="flex-1 text-sm text-muted-foreground">{t(($) => $.voice.recording)}</span>
        <Button type="button" variant="ghost" size="sm" onClick={discard} aria-label={t(($) => $.voice.discard)}>
          <Trash2 className="size-4" aria-hidden />
        </Button>
        <Button type="button" variant="secondary" size="sm" onClick={stop} className="gap-1.5">
          <Square className="size-3.5" aria-hidden />
          {t(($) => $.voice.stop)}
        </Button>
      </div>
    );
  }

  return (
    <div className="flex items-center gap-3 rounded-md border px-3 py-2">
      <audio controls src={state.url} className="h-9 max-w-[220px] flex-1" />
      <span className="shrink-0 text-xs tabular-nums text-muted-foreground">{formatElapsed(state.durationSeconds)}</span>
      <Button type="button" variant="ghost" size="sm" onClick={discard} aria-label={t(($) => $.voice.discard)}>
        <Trash2 className="size-4" aria-hidden />
      </Button>
      <Button
        type="button"
        size="sm"
        className="gap-1.5"
        onClick={() => onSend(state.blob, state.durationSeconds)}
      >
        <Send className="size-3.5" aria-hidden />
        {t(($) => $.voice.send)}
      </Button>
    </div>
  );
}
