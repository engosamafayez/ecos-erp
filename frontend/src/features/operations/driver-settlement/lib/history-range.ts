// History date-range presets (§17). Boundaries are computed as calendar dates and sent as
// Y-m-d; the backend resolves them server-side (DB timezone) against the settlement finalized
// date. Custom uses the two picker values, falling back to today when a side is blank.

export type HistoryPreset =
  | 'today'
  | 'this_week'
  | 'this_month'
  | 'previous_month'
  | 'this_year'
  | 'year_to_date'
  | 'previous_year'
  | 'custom';

export interface DateRange {
  from: string;
  to: string;
}

/** Local YYYY-MM-DD (never toISOString — that shifts to UTC and can roll the day). */
function iso(d: Date): string {
  const y = d.getFullYear();
  const m = String(d.getMonth() + 1).padStart(2, '0');
  const day = String(d.getDate()).padStart(2, '0');
  return `${y}-${m}-${day}`;
}

export function historyRange(preset: HistoryPreset, customFrom = '', customTo = ''): DateRange {
  const now = new Date();
  const y = now.getFullYear();
  const today = iso(now);

  switch (preset) {
    case 'today':
      return { from: today, to: today };
    case 'this_week': {
      const monday = new Date(now);
      monday.setDate(now.getDate() - ((now.getDay() + 6) % 7)); // Monday-based week start
      return { from: iso(monday), to: today };
    }
    case 'this_month':
      return { from: iso(new Date(y, now.getMonth(), 1)), to: today };
    case 'previous_month':
      return { from: iso(new Date(y, now.getMonth() - 1, 1)), to: iso(new Date(y, now.getMonth(), 0)) };
    case 'this_year':
      return { from: iso(new Date(y, 0, 1)), to: iso(new Date(y, 11, 31)) };
    case 'year_to_date':
      return { from: iso(new Date(y, 0, 1)), to: today };
    case 'previous_year':
      return { from: iso(new Date(y - 1, 0, 1)), to: iso(new Date(y - 1, 11, 31)) };
    case 'custom':
      return { from: customFrom || today, to: customTo || today };
    default:
      return { from: today, to: today };
  }
}
