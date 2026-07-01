import { useEffect, useRef, useCallback } from 'react';

const BARCODE_THRESHOLD_MS = 50;
const MIN_BARCODE_LENGTH = 4;

type BarcodeScannerOptions = {
  onScan: (barcode: string) => void;
  enabled?: boolean;
};

/**
 * Detects HID barcode scanner input. Scanners emit rapid keystrokes
 * ending with Enter; human typing is too slow to meet the threshold.
 */
export function useBarcodeScanner({ onScan, enabled = true }: BarcodeScannerOptions) {
  const bufferRef = useRef<string>('');
  const lastKeyTimeRef = useRef<number>(0);

  const handleKeyDown = useCallback(
    (e: KeyboardEvent) => {
      if (!enabled) return;

      // Ignore events when focus is on an input the user is typing into
      const tag = (e.target as HTMLElement)?.tagName;
      if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT') return;

      const now = Date.now();
      const delta = now - lastKeyTimeRef.current;
      lastKeyTimeRef.current = now;

      if (e.key === 'Enter') {
        const barcode = bufferRef.current;
        bufferRef.current = '';
        if (barcode.length >= MIN_BARCODE_LENGTH) {
          onScan(barcode);
        }
        return;
      }

      if (delta > BARCODE_THRESHOLD_MS * 10 || bufferRef.current.length === 0) {
        // New scan or too slow — only keep this char if delta is within threshold
        // of what a scanner would emit (or first char)
        if (delta > BARCODE_THRESHOLD_MS * 10) {
          bufferRef.current = '';
        }
      }

      if (e.key.length === 1) {
        bufferRef.current += e.key;
      }
    },
    [onScan, enabled],
  );

  useEffect(() => {
    if (!enabled) return;
    window.addEventListener('keydown', handleKeyDown);
    return () => window.removeEventListener('keydown', handleKeyDown);
  }, [handleKeyDown, enabled]);
}
