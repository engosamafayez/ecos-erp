import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { useParams } from 'react-router-dom';

import { useToast } from '@/components/ds/use-toast';
import { ErrorState, LoadingState, PageHeader } from '@/components/crud';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Textarea } from '@/components/ui/textarea';
import { Can } from '@/features/authorization/components/can';
import { useEmployeePerformanceQuery, useSaveManagerReview } from '@/features/hr/hooks/use-compensation';
import type { ManagerReview, PerformanceStatusKey, SaveManagerReviewPayload } from '@/features/hr/types/compensation';
import { ROUTES } from '@/router/routes';

const currentMonth = () => new Date().toISOString().slice(0, 7);

const STATUS_COLOR: Record<PerformanceStatusKey, string> = {
  exceeded: 'text-emerald-600',
  achieved: 'text-emerald-600',
  on_track: 'text-sky-600',
  at_risk: 'text-amber-600',
  missed: 'text-red-600',
};

const BAR_COLOR: Record<PerformanceStatusKey, string> = {
  exceeded: 'bg-emerald-500',
  achieved: 'bg-emerald-500',
  on_track: 'bg-sky-500',
  at_risk: 'bg-amber-500',
  missed: 'bg-red-500',
};

/**
 * Employee Performance Dashboard — target, actual, achievement and status.
 *
 * Every actual is collected from the operational modules, so the number on this
 * page is the same one the commission engine and the bonus recommendation read.
 */
export function EmployeePerformancePage() {
  const { t } = useTranslation('hr');
  const { employeeId = '' } = useParams();
  const [month, setMonth] = useState(currentMonth());

  const { data, isLoading, isError, refetch } = useEmployeePerformanceQuery(employeeId, month);

  if (isLoading) return <LoadingState />;
  if (isError || !data) return <ErrorState onRetry={() => void refetch()} />;

  const { employee, overall, goals, measured_metrics: measured, review, history } = data;
  const overallStatus = (overall.status as PerformanceStatusKey) ?? 'missed';
  const maxHistory = Math.max(100, ...history.map((h) => h.achievement_percent));

  return (
    <div className="flex flex-col gap-6">
      <PageHeader
        title={`${employee.name} — ${t(($) => $.performance.breadcrumb)}`}
        subtitle={`${employee.employee_number} · ${month}`}
        breadcrumbs={[
          { label: t(($) => $.performance.breadcrumbWorkforce), to: ROUTES.hr },
          { label: t(($) => $.performance.breadcrumb), to: ROUTES.hrPerformance },
          { label: employee.name },
        ]}
        actions={
          <input
            type="month"
            value={month}
            onChange={(e) => setMonth(e.target.value)}
            className="border-input h-9 rounded-md border bg-transparent px-3 text-sm shadow-xs"
          />
        }
      />

      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <Card>
          <CardContent className="pt-6">
            <div className="text-muted-foreground text-sm">{t(($) => $.performance.stats.overallAchievement)}</div>
            <div className={`text-2xl font-bold ${STATUS_COLOR[overallStatus] ?? ''}`}>
              {overall.achievement_percent}%
            </div>
          </CardContent>
        </Card>
        <Card>
          <CardContent className="pt-6">
            <div className="text-muted-foreground text-sm">{t(($) => $.performance.stats.goals)}</div>
            <div className="text-2xl font-bold">{overall.goals}</div>
          </CardContent>
        </Card>
        <Card>
          <CardContent className="pt-6">
            <div className="text-muted-foreground text-sm">{t(($) => $.performance.stats.targetsMet)}</div>
            <div className="text-2xl font-bold text-emerald-600">{overall.met_targets ?? 0}</div>
          </CardContent>
        </Card>
        <Card>
          <CardContent className="pt-6">
            <div className="text-muted-foreground text-sm">{t(($) => $.performance.stats.managerRating)}</div>
            <div className="text-2xl font-bold">{review ? `${review.overall_rating}/5` : '—'}</div>
          </CardContent>
        </Card>
      </div>

      <Card>
        <CardContent className="flex flex-col gap-4 pt-6">
          <h2 className="font-semibold">{t(($) => $.performance.goalsCard.title)}</h2>
          {goals.length === 0 ? (
            <p className="text-muted-foreground py-6 text-center text-sm">
              {t(($) => $.performance.goalsCard.emptyFor, { month })}
            </p>
          ) : (
            <div className="flex flex-col gap-4">
              {goals.map((goal) => (
                <div key={goal.metric_key} className="flex flex-col gap-1.5">
                  <div className="flex items-center justify-between text-sm">
                    <span className="font-medium">{goal.label}</span>
                    <span className={`tabular-nums ${STATUS_COLOR[goal.status]}`}>
                      {goal.achievement_percent}% · {goal.status_label}
                    </span>
                  </div>
                  <div className="bg-muted h-2 w-full overflow-hidden rounded-full">
                    <div
                      className={`h-full rounded-full ${BAR_COLOR[goal.status]}`}
                      style={{ width: `${Math.min(100, goal.achievement_percent)}%` }}
                    />
                  </div>
                  <div className="text-muted-foreground flex items-center justify-between text-xs">
                    <span>
                      {t(($) => $.performance.goalsCard.actual)} {goal.actual.toLocaleString()} {t(($) => $.performance.goalsCard.from)} {goal.target.toLocaleString()}
                    </span>
                    <span>
                      {goal.facts} {t(($) => (goal.facts === 1 ? $.performance.goalsCard.fact : $.performance.goalsCard.facts))} {t(($) => $.performance.goalsCard.from)} {goal.module ?? '—'}
                    </span>
                  </div>
                </div>
              ))}
            </div>
          )}
        </CardContent>
      </Card>

      <div className="grid gap-6 lg:grid-cols-2">
        <Card>
          <CardContent className="flex flex-col gap-4 pt-6">
            <h2 className="font-semibold">{t(($) => $.performance.trend.title)}</h2>
            {history.length === 0 ? (
              <p className="text-muted-foreground text-sm">{t(($) => $.performance.trend.empty)}</p>
            ) : (
              <div className="flex h-40 items-end gap-2">
                {history.map((point) => (
                  <div key={point.period_month} className="flex flex-1 flex-col items-center gap-1">
                    <div
                      className={`w-full rounded-t ${BAR_COLOR[point.status]}`}
                      style={{ height: `${Math.max(4, (point.achievement_percent / maxHistory) * 100)}%` }}
                      title={`${point.achievement_percent}%`}
                    />
                    <span className="text-muted-foreground text-[10px]">{point.period_month.slice(5)}</span>
                  </div>
                ))}
              </div>
            )}
          </CardContent>
        </Card>

        <Card>
          <CardContent className="flex flex-col gap-4 pt-6">
            <h2 className="font-semibold">{t(($) => $.performance.measured.title)}</h2>
            {/* What was collected, whether or not anyone set a target for it. */}
            {measured.length === 0 ? (
              <p className="text-muted-foreground text-sm">{t(($) => $.performance.measured.empty)}</p>
            ) : (
              <ul className="flex flex-col gap-2">
                {measured.map((metric) => (
                  <li key={metric.metric_key} className="flex items-center justify-between rounded-md border px-3 py-2">
                    <div className="flex flex-col">
                      <span className="text-sm font-medium">{metric.label}</span>
                      <span className="text-muted-foreground text-xs">
                        {t(($) => $.performance.measured.from)} {metric.module ?? '—'}
                      </span>
                    </div>
                    <span className="text-sm tabular-nums">{metric.actual.toLocaleString()}</span>
                  </li>
                ))}
              </ul>
            )}
          </CardContent>
        </Card>
      </div>

      <ManagerReviewCard employeeId={employee.id} periodMonth={month} review={review} />
    </div>
  );
}

/**
 * Read-only by default; the write form only ever renders for a caller holding
 * hr.performance.review — a caller without it sees exactly the same read-only
 * card whether or not a review exists yet, never a disabled/broken form.
 */
function ManagerReviewCard({
  employeeId,
  periodMonth,
  review,
}: {
  employeeId: string;
  periodMonth: string;
  review: ManagerReview | null;
}) {
  const { t } = useTranslation('hr');
  const [editing, setEditing] = useState(false);

  return (
    <Card>
      <CardContent className="flex flex-col gap-3 pt-6">
        <div className="flex items-center justify-between">
          <h2 className="font-semibold">{t(($) => $.performance.review.title)}</h2>
          <Can permission="hr.performance.review">
            {!editing && (
              <Button size="sm" variant="outline" onClick={() => setEditing(true)}>
                {review ? t(($) => $.performance.review.edit) : t(($) => $.performance.review.write)}
              </Button>
            )}
          </Can>
        </div>

        {editing ? (
          <ManagerReviewForm
            employeeId={employeeId}
            periodMonth={periodMonth}
            review={review}
            onDone={() => setEditing(false)}
          />
        ) : review ? (
          <div className="grid gap-3 sm:grid-cols-3">
            <div>
              <div className="text-muted-foreground text-xs uppercase">{t(($) => $.performance.review.strengths)}</div>
              <p className="text-sm">{review.strengths ?? '—'}</p>
            </div>
            <div>
              <div className="text-muted-foreground text-xs uppercase">{t(($) => $.performance.review.toImprove)}</div>
              <p className="text-sm">{review.improvement_notes ?? '—'}</p>
            </div>
            <div>
              <div className="text-muted-foreground text-xs uppercase">{t(($) => $.performance.review.comments)}</div>
              <p className="text-sm">{review.manager_comments ?? '—'}</p>
            </div>
          </div>
        ) : (
          <p className="text-muted-foreground py-4 text-center text-sm">
            {t(($) => $.performance.review.emptyFor, { month: periodMonth })}
          </p>
        )}
      </CardContent>
    </Card>
  );
}

function ManagerReviewForm({
  employeeId,
  periodMonth,
  review,
  onDone,
}: {
  employeeId: string;
  periodMonth: string;
  review: ManagerReview | null;
  onDone: () => void;
}) {
  const { t } = useTranslation('hr');
  const { toast } = useToast();
  const saveReview = useSaveManagerReview();

  const [rating, setRating] = useState(review?.overall_rating ?? 3);
  const [strengths, setStrengths] = useState(review?.strengths ?? '');
  const [improvementNotes, setImprovementNotes] = useState(review?.improvement_notes ?? '');
  const [managerComments, setManagerComments] = useState(review?.manager_comments ?? '');
  const [fieldErrors, setFieldErrors] = useState<Record<string, string[]>>({});

  const submitReview = (status: 'draft' | 'submitted') => {
    setFieldErrors({});

    const payload: SaveManagerReviewPayload = {
      period_month: periodMonth,
      overall_rating: rating,
      strengths: strengths || undefined,
      improvement_notes: improvementNotes || undefined,
      manager_comments: managerComments || undefined,
      status,
    };

    saveReview.mutate(
      { employeeId, ...payload },
      {
        onSuccess: () => {
          toast({
            title: status === 'submitted'
              ? t(($) => $.performance.review.toastSubmitted)
              : t(($) => $.performance.review.toastDraftSaved),
          });
          onDone();
        },
        onError: (error: unknown) => {
          const response = (error as { response?: { status?: number; data?: { errors?: Record<string, string[]> } } })
            .response;

          if (response?.status === 422 && response.data?.errors) {
            setFieldErrors(response.data.errors);
            return;
          }

          toast({
            title: response?.status === 404
              ? t(($) => $.performance.review.toastNotAuthorized)
              : t(($) => $.performance.review.toastSaveFailed),
            variant: 'destructive',
          });
        },
      },
    );
  };

  return (
    <div className="flex flex-col gap-4">
      <div className="flex flex-col gap-1.5">
        <label className="text-xs font-medium uppercase" htmlFor="overall_rating">
          {t(($) => $.performance.review.overallRating)}
        </label>
        <select
          id="overall_rating"
          value={rating}
          onChange={(e) => setRating(Number(e.target.value))}
          className="border-input h-9 w-24 rounded-md border bg-transparent px-3 text-sm shadow-xs"
        >
          {[1, 2, 3, 4, 5].map((value) => (
            <option key={value} value={value}>
              {value} / 5
            </option>
          ))}
        </select>
      </div>

      <div className="grid gap-4 sm:grid-cols-3">
        <div className="flex flex-col gap-1.5">
          <label className="text-xs font-medium uppercase" htmlFor="strengths">{t(($) => $.performance.review.strengths)}</label>
          <Textarea id="strengths" value={strengths} onChange={(e) => setStrengths(e.target.value)} rows={4} />
          {fieldErrors.strengths?.map((message) => (
            <p key={message} className="text-destructive text-xs">{message}</p>
          ))}
        </div>
        <div className="flex flex-col gap-1.5">
          <label className="text-xs font-medium uppercase" htmlFor="improvement_notes">{t(($) => $.performance.review.toImprove)}</label>
          <Textarea
            id="improvement_notes"
            value={improvementNotes}
            onChange={(e) => setImprovementNotes(e.target.value)}
            rows={4}
          />
          {fieldErrors.improvement_notes?.map((message) => (
            <p key={message} className="text-destructive text-xs">{message}</p>
          ))}
        </div>
        <div className="flex flex-col gap-1.5">
          <label className="text-xs font-medium uppercase" htmlFor="manager_comments">{t(($) => $.performance.review.comments)}</label>
          <Textarea
            id="manager_comments"
            value={managerComments}
            onChange={(e) => setManagerComments(e.target.value)}
            rows={4}
          />
          {fieldErrors.manager_comments?.map((message) => (
            <p key={message} className="text-destructive text-xs">{message}</p>
          ))}
        </div>
      </div>

      <div className="flex justify-end gap-2">
        <Button size="sm" variant="ghost" onClick={onDone} disabled={saveReview.isPending}>
          {t(($) => $.performance.review.cancel)}
        </Button>
        <Button size="sm" variant="outline" onClick={() => submitReview('draft')} disabled={saveReview.isPending}>
          {t(($) => $.performance.review.saveDraft)}
        </Button>
        <Button size="sm" onClick={() => submitReview('submitted')} disabled={saveReview.isPending}>
          {t(($) => $.performance.review.submit)}
        </Button>
      </div>
    </div>
  );
}
