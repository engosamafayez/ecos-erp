import { useTranslation } from 'react-i18next';
import { Pause, Play, RotateCcw } from 'lucide-react';

import { Button } from '@/components/ui/button';
import type enLogistics from '@/i18n/locales/en/logistics.json';

import { REPLAY_SPEEDS, type ReplaySpeed } from '../hooks/use-route-replay';
import type { RouteHistorySample } from '../types/live-map';

type LogisticsLabel = ($: typeof enLogistics) => string;

/** TASK-ECOS-SHIPPING-OS-REDESIGN-004 §18 — Play/Pause/Restart/scrubber/speed. */
export function RouteReplayControls({
  samples,
  index,
  isPlaying,
  speed,
  onPlay,
  onPause,
  onRestart,
  onSeek,
  onSpeedChange,
}: {
  samples: RouteHistorySample[];
  index: number;
  isPlaying: boolean;
  speed: ReplaySpeed;
  onPlay: () => void;
  onPause: () => void;
  onRestart: () => void;
  onSeek: (index: number) => void;
  onSpeedChange: (speed: ReplaySpeed) => void;
}) {
  const { t, i18n } = useTranslation('logistics');
  const current: RouteHistorySample | undefined = samples[index];

  const restartLabel: LogisticsLabel = ($) => $.trips.locationHistory.replay.restart;
  const playLabel: LogisticsLabel = ($) => $.trips.locationHistory.replay.play;
  const pauseLabel: LogisticsLabel = ($) => $.trips.locationHistory.replay.pause;
  const speedLabel: LogisticsLabel = ($) => $.trips.locationHistory.replay.speed;

  if (samples.length === 0) {
    return null;
  }

  return (
    <div className="flex flex-col gap-2 rounded-lg border p-3" data-testid="route-replay-controls">
      <div className="flex items-center gap-2">
        <Button
          size="icon"
          variant="outline"
          className="size-8 shrink-0"
          onClick={onRestart}
          aria-label={t(restartLabel)}
          data-testid="replay-restart"
        >
          <RotateCcw className="size-3.5" />
        </Button>
        <Button
          size="icon"
          variant="outline"
          className="size-8 shrink-0"
          onClick={isPlaying ? onPause : onPlay}
          aria-label={isPlaying ? t(pauseLabel) : t(playLabel)}
          data-testid="replay-play-pause"
        >
          {isPlaying ? <Pause className="size-3.5" /> : <Play className="size-3.5" />}
        </Button>

        <input
          type="range"
          min={0}
          max={Math.max(0, samples.length - 1)}
          value={index}
          onChange={(e) => onSeek(Number(e.target.value))}
          className="mx-2 h-1.5 flex-1 accent-primary"
          aria-label={t(($) => $.trips.locationHistory.tab)}
          data-testid="replay-scrubber"
        />

        <div className="flex shrink-0 items-center gap-1" aria-label={t(speedLabel)}>
          {REPLAY_SPEEDS.map((s) => (
            <Button
              key={s}
              size="sm"
              variant={speed === s ? 'secondary' : 'ghost'}
              className="h-7 px-2 text-xs"
              onClick={() => onSpeedChange(s)}
              data-testid={`replay-speed-${s}`}
            >
              {s}x
            </Button>
          ))}
        </div>
      </div>

      <span className="text-xs text-muted-foreground">
        {current ? new Date(current.recorded_at).toLocaleString(i18n.language) : ''}
      </span>
    </div>
  );
}
