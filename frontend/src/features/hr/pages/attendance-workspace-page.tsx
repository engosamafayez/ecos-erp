import { Fragment, useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { CalendarDays, Check, Save } from 'lucide-react';

import { ErrorState, LoadingState, PageHeader } from '@/components/crud';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Can } from '@/features/authorization/components/can';

import type enHr from '@/i18n/locales/en/hr.json';

/**
 * A label held as an i18next selector rather than a key string.
 *
 * Selector mode has no type for a key chosen at runtime, so a table of
 * key strings can never type-check. The selector is the same expression
 * the compiler validates at an inline call site, kept in the table.
 */
type HrLabel = ($: typeof enHr) => string;
import {
  useAttendanceCorrectionsQuery,
  useAttendanceDaysQuery,
  useAttendanceSheetQuery,
  useDecideAttendanceCorrection,
  useDepartmentsQuery,
  useRegisterAttendance,
  useRequestAttendanceCorrection,
} from '@/features/hr/hooks/use-hr';
import type { AttendanceStatus, EarlyLeaveState, LateState, WorkedTimeState } from '@/features/hr/types/hr';

const today = () => new Date().toISOString().slice(0, 10);

const STATUS_OPTIONS: Array<{ value: AttendanceStatus; labelKey: HrLabel }> = [
  { value: 'present', labelKey: ($) => $.attendance.status.present },
  { value: 'absent', labelKey: ($) => $.attendance.status.absent },
  { value: 'leave', labelKey: ($) => $.attendance.status.leave },
  { value: 'holiday', labelKey: ($) => $.attendance.status.holiday },
  { value: 'rest_day', labelKey: ($) => $.attendance.status.restDay },
];

const formatMinutes = (minutes: number): string => {
  const h = Math.floor(minutes / 60);
  const m = minutes % 60;
  return h > 0 ? `${h}h ${m}m` : `${m}m`;
};

/** Never a guessed On Time/Late/0 — an unavailable input renders as a dash and a reason, not a status. */
function LateCell({ late }: { late: LateState | null }) {
  const { t } = useTranslation('hr');
  if (!late || late.status === 'not_applicable') return <span className="text-muted-foreground">—</span>;
  if (late.status === 'not_evaluated') {
    return <span className="text-muted-foreground text-xs">{t(($) => $.attendance.time.notEvaluated)}</span>;
  }
  return late.status === 'late' ? (
    <span className="text-red-600">
      {t(($) => $.attendance.time.late)} · {late.minutes_late}m
    </span>
  ) : (
    <span className="text-emerald-600">{t(($) => $.attendance.time.onTime)}</span>
  );
}

function EarlyLeaveCell({ earlyLeave }: { earlyLeave: EarlyLeaveState | null }) {
  const { t } = useTranslation('hr');
  if (!earlyLeave || earlyLeave.status === 'not_applicable') return <span className="text-muted-foreground">—</span>;
  if (earlyLeave.status === 'not_evaluated') {
    return <span className="text-muted-foreground text-xs">{t(($) => $.attendance.time.notEvaluated)}</span>;
  }
  return earlyLeave.status === 'early' ? (
    <span className="text-amber-600">
      {t(($) => $.attendance.time.early)} · {earlyLeave.minutes_early}m
    </span>
  ) : (
    <span className="text-emerald-600">{t(($) => $.attendance.time.onTime)}</span>
  );
}

function WorkedTimeCell({ worked }: { worked: WorkedTimeState | null }) {
  const { t } = useTranslation('hr');
  if (!worked || worked.status !== 'available' || worked.net_minutes === null) {
    return (
      <span className="text-muted-foreground text-xs">
        {worked?.status === 'not_applicable' ? '—' : t(($) => $.attendance.time.notEvaluated)}
      </span>
    );
  }
  return <span>{formatMinutes(worked.net_minutes)}</span>;
}

/**
 * Attendance Workspace — manual registration, the way a supervisor works.
 *
 * The sheet lists everyone for a date with whatever is already recorded, the
 * whole team is marked in one pass, and re-registering a day corrects it rather
 * than duplicating it. No device capture of any kind: this is entered by a person.
 */
export function AttendanceWorkspacePage() {
  const { t } = useTranslation('hr');
  const [date, setDate] = useState(today());
  const [departmentId, setDepartmentId] = useState('');
  const [draft, setDraft] = useState<Record<string, AttendanceStatus>>({});
  const [saved, setSaved] = useState<string | null>(null);

  const params = useMemo(
    () => ({ date, department_id: departmentId || undefined }),
    [date, departmentId],
  );

  const { data: sheet, isLoading, isError, refetch } = useAttendanceSheetQuery(params);
  const { data: departments } = useDepartmentsQuery();
  const register = useRegisterAttendance();

  // ── History + corrections (FIN-01) ─────────────────────────────────────────
  const [historyFrom, setHistoryFrom] = useState(() => {
    const d = new Date();
    d.setDate(d.getDate() - 13);
    return d.toISOString().slice(0, 10);
  });
  const [historyTo, setHistoryTo] = useState(today());
  const historyParams = useMemo(() => ({ from: historyFrom, to: historyTo }), [historyFrom, historyTo]);

  const { data: history, isLoading: historyLoading } = useAttendanceDaysQuery(historyParams);
  const { data: corrections, isLoading: correctionsLoading } = useAttendanceCorrectionsQuery({ status: 'pending' });
  const requestCorrection = useRequestAttendanceCorrection();
  const decideCorrection = useDecideAttendanceCorrection();

  const [correctingDayId, setCorrectingDayId] = useState<string | null>(null);
  const [correctionDraft, setCorrectionDraft] = useState({ check_in: '', check_out: '', reason: '' });

  const submitCorrection = async () => {
    if (!correctingDayId) return;
    await requestCorrection.mutateAsync({
      attendanceDayId: correctingDayId,
      check_in: correctionDraft.check_in || undefined,
      check_out: correctionDraft.check_out || undefined,
      reason: correctionDraft.reason,
    });
    setCorrectingDayId(null);
    setCorrectionDraft({ check_in: '', check_out: '', reason: '' });
  };

  // Seed the draft from what is already recorded; unrecorded rows take the
  // suggested status for the day (holiday, rest day, or present).
  //
  // Seeded during render rather than in an effect. As an effect this painted an
  // empty sheet first and filled it on the next pass, so every date change
  // flashed a blank register before the real one appeared.
  const [seededSheet, setSeededSheet] = useState<typeof sheet>(undefined);

  if (sheet && sheet !== seededSheet) {
    setSeededSheet(sheet);

    const next: Record<string, AttendanceStatus> = {};
    for (const row of sheet.employees) {
      next[row.employee_id] = row.status ?? sheet.suggested_status;
    }
    setDraft(next);
    setSaved(null);
  }

  const rows = sheet?.employees ?? [];
  const counts = useMemo(() => {
    const tally: Record<string, number> = {};
    for (const status of Object.values(draft)) {
      tally[status] = (tally[status] ?? 0) + 1;
    }
    return tally;
  }, [draft]);

  const markAll = (status: AttendanceStatus) => {
    setDraft(Object.fromEntries(rows.map((row) => [row.employee_id, status])));
  };

  const save = async () => {
    const entries = rows.map((row) => ({
      employee_id: row.employee_id,
      status: draft[row.employee_id] ?? 'present',
    }));

    const result = await register.mutateAsync({ work_date: date, entries });
    setSaved(t($ => $.attendance.savedSummary, { registered: result.registered, total: entries.length }));
  };

  if (isLoading) return <LoadingState />;
  if (isError || !sheet) return <ErrorState onRetry={() => void refetch()} />;

  return (
    <div className="flex flex-col gap-6">
      <PageHeader
        title={t($ => $.attendance.title)}
        subtitle={t($ => $.attendance.subtitle)}
        actions={
          <div className="flex items-center gap-2">
            <input
              type="date"
              value={date}
              max={today()}
              onChange={(e) => setDate(e.target.value)}
              className="border-input h-9 rounded-md border bg-transparent px-3 text-sm shadow-xs"
            />
            <Button size="sm" onClick={() => void save()} disabled={register.isPending || rows.length === 0}>
              <Save className="size-4" />
              {register.isPending ? t($ => $.common.saving) : t($ => $.attendance.saveRegister)}
            </Button>
          </div>
        }
      />

      {sheet.holiday ? (
        <Card>
          <CardContent className="flex items-center gap-3 pt-6">
            <CalendarDays className="size-5 text-purple-600" />
            <div className="text-sm">
              <span className="font-medium">{sheet.holiday.name}</span> {t($ => $.attendance.holidayNotice)}
            </div>
          </CardContent>
        </Card>
      ) : null}

      {!sheet.is_working_day && !sheet.holiday ? (
        <Card>
          <CardContent className="flex items-center gap-3 pt-6">
            <CalendarDays className="size-5 text-slate-500" />
            <div className="text-sm">{t($ => $.attendance.nonWorkingDayNotice)}</div>
          </CardContent>
        </Card>
      ) : null}

      {saved ? (
        <Card>
          <CardContent className="flex items-center gap-3 pt-6">
            <Check className="size-5 text-emerald-600" />
            <div className="text-sm font-medium">{saved}</div>
          </CardContent>
        </Card>
      ) : null}

      <Card>
        <CardContent className="flex flex-col gap-4 pt-6">
          <div className="flex flex-wrap items-center justify-between gap-3">
            <div className="flex items-center gap-2">
              <span className="text-sm font-medium">{t($ => $.common.department)}</span>
              <select
                value={departmentId}
                onChange={(e) => setDepartmentId(e.target.value)}
                className="border-input h-9 rounded-md border bg-transparent px-3 text-sm shadow-xs"
              >
                <option value="">{t($ => $.common.allDepartments)}</option>
                {(departments ?? []).map((d) => (
                  <option key={d.id} value={d.id}>
                    {d.name}
                  </option>
                ))}
              </select>
            </div>

            <div className="flex items-center gap-2">
              <span className="text-muted-foreground text-sm">{t($ => $.attendance.markAll)}</span>
              {STATUS_OPTIONS.map((option) => (
                <Button
                  key={option.value}
                  variant="outline"
                  size="sm"
                  onClick={() => markAll(option.value)}
                >
                  {t(option.labelKey)}
                </Button>
              ))}
            </div>
          </div>

          <div className="flex flex-wrap gap-4 text-sm">
            <span className="text-emerald-600">
              {t($ => $.attendance.status.present)}: {counts.present ?? 0}
            </span>
            <span className="text-red-600">
              {t($ => $.attendance.status.absent)}: {counts.absent ?? 0}
            </span>
            <span className="text-amber-600">
              {t($ => $.attendance.status.leave)}: {counts.leave ?? 0}
            </span>
            <span className="text-purple-600">
              {t($ => $.attendance.status.holiday)}: {counts.holiday ?? 0}
            </span>
            <span className="text-slate-500">
              {t($ => $.attendance.status.restDay)}: {counts.rest_day ?? 0}
            </span>
          </div>

          {rows.length === 0 ? (
            <p className="text-muted-foreground py-8 text-center text-sm">{t($ => $.attendance.emptySheet)}</p>
          ) : (
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead className="text-muted-foreground border-b text-start text-xs uppercase">
                  <tr>
                    <th className="py-2 pe-4 font-medium">{t($ => $.attendance.table.number)}</th>
                    <th className="py-2 pe-4 font-medium">{t($ => $.attendance.table.employee)}</th>
                    <th className="py-2 pe-4 font-medium">{t($ => $.attendance.table.department)}</th>
                    <th className="py-2 pe-4 font-medium">{t($ => $.attendance.table.recorded)}</th>
                    <th className="py-2 pe-4 font-medium">{t($ => $.attendance.table.status)}</th>
                  </tr>
                </thead>
                <tbody>
                  {rows.map((row) => (
                    <tr key={row.employee_id} className="border-b last:border-0">
                      <td className="py-2 pe-4 font-mono text-xs">{row.employee_number}</td>
                      <td className="py-2 pe-4 font-medium">{row.name}</td>
                      <td className="text-muted-foreground py-2 pe-4">{row.department ?? '—'}</td>
                      <td className="py-2 pe-4">
                        {row.registered ? (
                          <span className="text-emerald-600 text-xs">{t($ => $.attendance.recorded)}</span>
                        ) : (
                          <span className="text-muted-foreground text-xs">{t($ => $.attendance.notYet)}</span>
                        )}
                      </td>
                      <td className="py-2 pe-4">
                        <select
                          value={draft[row.employee_id] ?? 'present'}
                          onChange={(e) =>
                            setDraft((prev) => ({
                              ...prev,
                              [row.employee_id]: e.target.value as AttendanceStatus,
                            }))
                          }
                          className="border-input h-8 rounded-md border bg-transparent px-2 text-sm shadow-xs"
                        >
                          {STATUS_OPTIONS.map((option) => (
                            <option key={option.value} value={option.value}>
                              {t(option.labelKey)}
                            </option>
                          ))}
                        </select>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </CardContent>
      </Card>

      <Card>
        <CardContent className="flex flex-col gap-4 pt-6">
          <div className="flex flex-wrap items-center justify-between gap-3">
            <h2 className="font-semibold">{t(($) => $.attendance.history.title)}</h2>
            <div className="flex items-center gap-2">
              <input
                type="date"
                value={historyFrom}
                max={historyTo}
                onChange={(e) => setHistoryFrom(e.target.value)}
                className="border-input h-9 rounded-md border bg-transparent px-3 text-sm shadow-xs"
              />
              <span className="text-muted-foreground text-sm">–</span>
              <input
                type="date"
                value={historyTo}
                min={historyFrom}
                max={today()}
                onChange={(e) => setHistoryTo(e.target.value)}
                className="border-input h-9 rounded-md border bg-transparent px-3 text-sm shadow-xs"
              />
            </div>
          </div>

          {historyLoading ? (
            <LoadingState />
          ) : !history || history.items.length === 0 ? (
            <p className="text-muted-foreground py-8 text-center text-sm">{t(($) => $.attendance.history.empty)}</p>
          ) : (
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead className="text-muted-foreground border-b text-start text-xs uppercase">
                  <tr>
                    <th className="py-2 pe-4 font-medium">{t(($) => $.attendance.table.employee)}</th>
                    <th className="py-2 pe-4 font-medium">{t(($) => $.attendance.history.date)}</th>
                    <th className="py-2 pe-4 font-medium">{t(($) => $.attendance.table.status)}</th>
                    <th className="py-2 pe-4 font-medium">{t(($) => $.attendance.history.checkIn)}</th>
                    <th className="py-2 pe-4 font-medium">{t(($) => $.attendance.history.checkOut)}</th>
                    <th className="py-2 pe-4 font-medium">{t(($) => $.attendance.history.late)}</th>
                    <th className="py-2 pe-4 font-medium">{t(($) => $.attendance.history.earlyLeave)}</th>
                    <th className="py-2 pe-4 font-medium">{t(($) => $.attendance.history.workedTime)}</th>
                    <Can permission="hr.attendance.register">
                      <th className="py-2 pe-4 font-medium">{t(($) => $.attendance.history.action)}</th>
                    </Can>
                  </tr>
                </thead>
                <tbody>
                  {history.items.map((row) => (
                    <Fragment key={row.id}>
                      <tr className="border-b last:border-0">
                        <td className="py-2 pe-4 font-medium">{row.employee?.name ?? '—'}</td>
                        <td className="text-muted-foreground py-2 pe-4">{row.work_date}</td>
                        <td className="py-2 pe-4">{row.status_label}</td>
                        <td className="py-2 pe-4 tabular-nums">{row.check_in ?? '—'}</td>
                        <td className="py-2 pe-4 tabular-nums">{row.check_out ?? '—'}</td>
                        <td className="py-2 pe-4">
                          <LateCell late={row.late} />
                        </td>
                        <td className="py-2 pe-4">
                          <EarlyLeaveCell earlyLeave={row.early_leave} />
                        </td>
                        <td className="py-2 pe-4">
                          <WorkedTimeCell worked={row.worked_time} />
                        </td>
                        <Can permission="hr.attendance.register">
                          <td className="py-2 pe-4">
                            <Button
                              size="sm"
                              variant="outline"
                              onClick={() =>
                                setCorrectingDayId(correctingDayId === row.id ? null : row.id)
                              }
                            >
                              {t(($) => $.attendance.history.requestCorrection)}
                            </Button>
                          </td>
                        </Can>
                      </tr>
                      {correctingDayId === row.id ? (
                        <tr className="bg-muted/30 border-b last:border-0">
                          <td colSpan={9} className="p-3">
                            <div className="flex flex-wrap items-end gap-2">
                              <label className="flex flex-col gap-1 text-xs">
                                {t(($) => $.attendance.history.checkIn)}
                                <Input
                                  type="time"
                                  step={1}
                                  value={correctionDraft.check_in}
                                  onChange={(e) =>
                                    setCorrectionDraft((prev) => ({ ...prev, check_in: e.target.value }))
                                  }
                                  className="h-8 w-28"
                                />
                              </label>
                              <label className="flex flex-col gap-1 text-xs">
                                {t(($) => $.attendance.history.checkOut)}
                                <Input
                                  type="time"
                                  step={1}
                                  value={correctionDraft.check_out}
                                  onChange={(e) =>
                                    setCorrectionDraft((prev) => ({ ...prev, check_out: e.target.value }))
                                  }
                                  className="h-8 w-28"
                                />
                              </label>
                              <label className="flex flex-1 flex-col gap-1 text-xs">
                                {t(($) => $.attendance.correction.reason)}
                                <Input
                                  value={correctionDraft.reason}
                                  onChange={(e) =>
                                    setCorrectionDraft((prev) => ({ ...prev, reason: e.target.value }))
                                  }
                                  className="h-8"
                                />
                              </label>
                              <Button
                                size="sm"
                                disabled={!correctionDraft.reason || requestCorrection.isPending}
                                onClick={() => void submitCorrection()}
                              >
                                {t(($) => $.attendance.history.submitCorrection)}
                              </Button>
                              <Button size="sm" variant="outline" onClick={() => setCorrectingDayId(null)}>
                                {t(($) => $.common.cancel)}
                              </Button>
                            </div>
                          </td>
                        </tr>
                      ) : null}
                    </Fragment>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </CardContent>
      </Card>

      <Can permission="hr.attendance.register">
        <Card>
          <CardContent className="flex flex-col gap-4 pt-6">
            <h2 className="font-semibold">{t(($) => $.attendance.correction.pendingTitle)}</h2>
            {correctionsLoading ? (
              <LoadingState />
            ) : !corrections || corrections.length === 0 ? (
              <p className="text-muted-foreground py-6 text-center text-sm">{t(($) => $.attendance.correction.empty)}</p>
            ) : (
              <ul className="flex flex-col gap-2">
                {corrections.map((c) => (
                  <li key={c.id} className="flex flex-col gap-2 rounded-md border px-3 py-2 text-sm">
                    <div className="flex flex-wrap items-center justify-between gap-2">
                      <span className="font-medium">
                        {c.employee?.name ?? '—'} · {c.work_date}
                      </span>
                      <div className="flex gap-1">
                        <Button
                          size="sm"
                          onClick={() => void decideCorrection.mutateAsync({ id: c.id, decision: 'approve' })}
                        >
                          {t(($) => $.attendance.correction.approve)}
                        </Button>
                        <Button
                          size="sm"
                          variant="outline"
                          onClick={() => void decideCorrection.mutateAsync({ id: c.id, decision: 'reject' })}
                        >
                          {t(($) => $.attendance.correction.reject)}
                        </Button>
                      </div>
                    </div>
                    <div className="text-muted-foreground flex flex-wrap gap-3 text-xs">
                      <span>
                        {t(($) => $.attendance.history.checkIn)}: {c.original.check_in ?? '—'} → {c.corrected.check_in ?? '—'}
                      </span>
                      <span>
                        {t(($) => $.attendance.history.checkOut)}: {c.original.check_out ?? '—'} → {c.corrected.check_out ?? '—'}
                      </span>
                    </div>
                    <span className="text-xs">{c.reason}</span>
                  </li>
                ))}
              </ul>
            )}
          </CardContent>
        </Card>
      </Can>
    </div>
  );
}
