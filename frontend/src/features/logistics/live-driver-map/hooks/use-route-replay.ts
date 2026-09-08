import { useEffect, useState } from 'react';

export const REPLAY_SPEEDS = [1, 2, 4] as const;
export type ReplaySpeed = (typeof REPLAY_SPEEDS)[number];

/** Wall-clock milliseconds between advancing to the next sample, at 1x. */
const BASE_STEP_MS = 800;

/**
 * TASK-ECOS-SHIPPING-OS-REDESIGN-004 §18 — drives an index through a fixed
 * count of REAL recorded samples, one at a time, strictly in the order they
 * were returned. This never simulates a missing interval or interpolates a
 * position between two recorded points — each tick moves to the next
 * existing sample and nothing else; a large gap in real reporting time
 * between two samples plays back just as fast as a small one, because there
 * is nothing recorded to animate through it (§19).
 */
export function useRouteReplay(sampleCount: number) {
  const [index, setIndex] = useState(0);
  const [isPlaying, setIsPlaying] = useState(false);
  const [speed, setSpeed] = useState<ReplaySpeed>(1);

  // A freshly loaded or changed sample set starts stopped at the first point.
  // Adjusted DURING RENDER, not in an effect — React's own recommended
  // pattern for "reset state when a prop changes" (see "You Might Not Need
  // an Effect"), which also sidesteps the cascading-render risk the
  // react-hooks/set-state-in-effect lint rule flags for an effect-driven reset.
  const [prevSampleCount, setPrevSampleCount] = useState(sampleCount);
  if (sampleCount !== prevSampleCount) {
    setPrevSampleCount(sampleCount);
    setIndex(0);
    setIsPlaying(false);
  }

  useEffect(() => {
    if (!isPlaying || sampleCount === 0) {
      return;
    }

    const timer = setInterval(() => {
      setIndex((current) => {
        if (current >= sampleCount - 1) {
          setIsPlaying(false);
          return current;
        }
        return current + 1;
      });
    }, BASE_STEP_MS / speed);

    return () => clearInterval(timer);
  }, [isPlaying, speed, sampleCount]);

  function play() {
    if (sampleCount === 0) {
      return;
    }
    setIndex((current) => (current >= sampleCount - 1 ? 0 : current));
    setIsPlaying(true);
  }

  function pause() {
    setIsPlaying(false);
  }

  function restart() {
    setIndex(0);
    setIsPlaying(false);
  }

  function seek(next: number) {
    setIsPlaying(false);
    setIndex(Math.max(0, Math.min(sampleCount - 1, next)));
  }

  return { index, isPlaying, speed, setSpeed, play, pause, restart, seek };
}
