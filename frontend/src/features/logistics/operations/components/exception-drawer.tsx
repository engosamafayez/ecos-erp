import { useState } from 'react';
import { ArrowUpCircle, Pin, Repeat2 } from 'lucide-react';

import { PageDrawer } from '@/components/page/drawer/page-drawer';
import { useToast } from '@/components/ds/use-toast';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import { Separator } from '@/components/ui/separator';
import { Skeleton } from '@/components/ui/skeleton';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Textarea } from '@/components/ui/textarea';

import {
  useAcknowledgeException,
  useAddExceptionNote,
  useEscalateException,
  useEscalations,
  useException,
  useExceptionNotes,
  useResolveException,
  useSuppressException,
} from '../hooks/use-operations';
import type { ExceptionResolution } from '../types/operations';
import { ExceptionStatusBadge, SeverityIcon, SourceBadge } from './operations-badges';

function Field({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <div className="space-y-0.5">
      <p className="text-[11px] uppercase tracking-wide text-muted-foreground">{label}</p>
      <div className="text-sm">{children}</div>
    </div>
  );
}

function Notes({ exceptionId }: { exceptionId: string }) {
  const { toast } = useToast();
  const { data: notes, isLoading } = useExceptionNotes(exceptionId);
  const [body, setBody] = useState('');
  const [noteType, setNoteType] = useState<'note' | 'action_taken' | 'handover'>('note');
  const addNote = useAddExceptionNote();

  return (
    <div className="space-y-3">
      <div className="space-y-2">
        <Textarea
          value={body}
          onChange={(e) => setBody(e.target.value)}
          placeholder="What was tried, what was ruled out"
          rows={3}
          className="text-sm"
        />
        <div className="flex items-center gap-2">
          <Select value={noteType} onValueChange={(v) => setNoteType(v as typeof noteType)}>
            <SelectTrigger className="h-8 w-40 text-xs">
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="note" className="text-xs">
                Note
              </SelectItem>
              <SelectItem value="action_taken" className="text-xs">
                Action taken
              </SelectItem>
              <SelectItem value="handover" className="text-xs">
                Handover
              </SelectItem>
            </SelectContent>
          </Select>
          <Button
            size="sm"
            className="h-8 text-xs"
            disabled={body.trim().length === 0 || addNote.isPending}
            onClick={() =>
              addNote.mutate(
                { id: exceptionId, body: body.trim(), noteType },
                {
                  onSuccess: () => {
                    toast({ title: 'Note added.' });
                    setBody('');
                  },
                  onError: () =>
                    toast({ title: 'The note could not be added.', variant: 'destructive' }),
                },
              )
            }
          >
            Add
          </Button>
          {noteType === 'handover' && (
            // A handover is what the next shift reads first, so it pins itself.
            <span className="text-[11px] text-muted-foreground">Pins to the top</span>
          )}
        </div>
      </div>

      <Separator />

      {isLoading ? (
        <Skeleton className="h-32 w-full" />
      ) : !notes || notes.length === 0 ? (
        <p className="py-6 text-center text-sm text-muted-foreground">Nothing written yet.</p>
      ) : (
        <ul className="space-y-2">
          {notes.map((note) => (
            <li key={note.id} className="rounded-md border p-2.5">
              <div className="flex items-start gap-2">
                {note.is_pinned && <Pin className="mt-0.5 size-3 shrink-0 text-amber-600" />}
                <div className="min-w-0 flex-1">
                  <p className="text-sm">{note.body}</p>
                  <p className="mt-0.5 text-[11px] text-muted-foreground">
                    {note.author_name ?? 'Unknown'} ·{' '}
                    {note.written_at ? new Date(note.written_at).toLocaleString() : '—'}
                    {note.note_type !== 'note' ? ` · ${note.note_type.replace('_', ' ')}` : ''}
                  </p>
                </div>
              </div>
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}

function Escalations({ exceptionId }: { exceptionId: string }) {
  const { toast } = useToast();
  const { data: history } = useEscalations(exceptionId);
  const [reason, setReason] = useState('');
  const [role, setRole] = useState('');
  const escalate = useEscalateException();

  return (
    <div className="space-y-3">
      <div className="space-y-2">
        <div className="grid grid-cols-2 gap-2">
          <div className="space-y-1.5">
            <Label className="text-xs">Reason</Label>
            <Input
              value={reason}
              onChange={(e) => setReason(e.target.value)}
              placeholder="Why this needs someone else"
              className="h-8 text-xs"
            />
          </div>
          <div className="space-y-1.5">
            <Label className="text-xs">To role</Label>
            <Input
              value={role}
              onChange={(e) => setRole(e.target.value)}
              placeholder="operations_director"
              className="h-8 text-xs"
            />
          </div>
        </div>
        <Button
          size="sm"
          className="h-8 text-xs"
          // Handing someone a problem with no context is how escalations stall.
          disabled={reason.trim().length === 0 || escalate.isPending}
          onClick={() =>
            escalate.mutate(
              { id: exceptionId, reason: reason.trim(), toRole: role.trim() || undefined },
              {
                onSuccess: () => {
                  toast({ title: 'Escalated.' });
                  setReason('');
                },
                onError: () =>
                  toast({ title: 'The escalation was refused.', variant: 'destructive' }),
              },
            )
          }
        >
          <ArrowUpCircle className="mr-1 size-3.5" />
          Escalate
        </Button>
      </div>

      <Separator />

      {!history || history.length === 0 ? (
        <p className="py-6 text-center text-sm text-muted-foreground">Not escalated.</p>
      ) : (
        <ol className="space-y-2">
          {history.map((step) => (
            <li key={step.id} className="rounded-md border p-2.5 text-xs">
              <div className="flex items-center gap-2">
                <Badge variant="outline" className="text-[10px]">
                  Level {step.level}
                </Badge>
                {step.was_automatic && (
                  <Badge variant="outline" className="text-[10px]">
                    Automatic
                  </Badge>
                )}
                {step.escalated_to_role && (
                  <span className="text-muted-foreground">→ {step.escalated_to_role}</span>
                )}
              </div>
              <p className="mt-1">{step.reason}</p>
              <p className="mt-0.5 text-[11px] text-muted-foreground">
                {step.escalated_at ? new Date(step.escalated_at).toLocaleString() : '—'}
                {step.escalated_by_name ? ` · ${step.escalated_by_name}` : ''}
              </p>
            </li>
          ))}
        </ol>
      )}
    </div>
  );
}

/**
 * One exception, with everything an operator can do to it.
 *
 * The resolution options narrow when the exception belongs elsewhere: Operations
 * can record that another module dealt with it, but it cannot declare that
 * module's fact untrue.
 */
export function ExceptionDrawer({
  exceptionId,
  open,
  onOpenChange,
}: {
  exceptionId: string | null;
  open: boolean;
  onOpenChange: (open: boolean) => void;
}) {
  const { toast } = useToast();
  const { data: exception, isLoading } = useException(exceptionId);
  const [resolution, setResolution] = useState<ExceptionResolution>('handled_elsewhere');
  const [reason, setReason] = useState('');

  const acknowledge = useAcknowledgeException();
  const resolve = useResolveException();
  const suppress = useSuppressException();

  if (exceptionId === null) return null;

  const canResolveOutright = exception?.is_self_owned === true;

  return (
    <PageDrawer
      open={open}
      onOpenChange={onOpenChange}
      title={exception?.title ?? 'Exception'}
      description={exception ? `${exception.source_label} · ${exception.category_label}` : ''}
      size="2xl"
    >
      {isLoading || !exception ? (
        <Skeleton className="h-96 w-full" />
      ) : (
        <div className="space-y-5">
          <div className="flex flex-wrap items-center gap-2">
            <SeverityIcon severity={exception.severity} />
            <ExceptionStatusBadge status={exception.status} />
            <SourceBadge source={exception.source} label={exception.source_label} />
            {exception.is_recurring && (
              <Badge variant="outline" className="gap-1 text-[10px]">
                <Repeat2 className="size-2.5" />
                Seen {exception.occurrence_count} times
              </Badge>
            )}
            {exception.is_overdue_for_escalation && (
              <Badge variant="destructive" className="text-[10px]">
                Overdue
              </Badge>
            )}
          </div>

          {!exception.is_self_owned && (
            <Alert>
              <AlertDescription className="text-xs">
                This belongs to {exception.source_label}. Operations can record what was done, but
                the underlying problem has to be cleared there.
              </AlertDescription>
            </Alert>
          )}

          {exception.description && (
            // The owning module's own wording, not a paraphrase.
            <p className="text-sm text-muted-foreground">{exception.description}</p>
          )}

          <div className="grid grid-cols-2 gap-4">
            <Field label="Type">{exception.exception_type}</Field>
            <Field label="Severity">{exception.severity_label}</Field>
            <Field label="First seen">
              {exception.first_seen_at ? new Date(exception.first_seen_at).toLocaleString() : '—'}
            </Field>
            <Field label="Last seen">
              {exception.last_seen_at ? new Date(exception.last_seen_at).toLocaleString() : '—'}
            </Field>
            <Field label="Age">{exception.age_minutes} min</Field>
            <Field label="Unacknowledged">
              {exception.unacknowledged_minutes !== null
                ? `${exception.unacknowledged_minutes} min`
                : 'Acknowledged'}
            </Field>
            <Field label="Escalation level">{exception.escalation_level}</Field>
            <Field label="Subject">
              {exception.subject_type
                ? `${exception.subject_type} ${exception.subject_id ?? ''}`
                : '—'}
            </Field>
          </div>

          <Separator />

          <Tabs defaultValue="act" className="w-full">
            <TabsList>
              <TabsTrigger value="act">Act</TabsTrigger>
              <TabsTrigger value="notes">Notes</TabsTrigger>
              <TabsTrigger value="escalations">Escalations</TabsTrigger>
            </TabsList>

            <TabsContent value="act" className="space-y-3 pt-4">
              {exception.is_outstanding ? (
                <>
                  <Button
                    size="sm"
                    variant="outline"
                    className="h-8 text-xs"
                    disabled={exception.acknowledged_at !== null || acknowledge.isPending}
                    onClick={() =>
                      acknowledge.mutate(exception.id, {
                        onSuccess: () => toast({ title: 'Acknowledged.' }),
                        onError: () =>
                          toast({ title: 'That could not be acknowledged.', variant: 'destructive' }),
                      })
                    }
                  >
                    {exception.acknowledged_at !== null ? 'Already acknowledged' : 'Acknowledge'}
                  </Button>

                  <Separator />

                  <div className="grid grid-cols-2 gap-3">
                    <div className="space-y-1.5">
                      <Label className="text-xs">Resolution</Label>
                      <Select
                        value={resolution}
                        onValueChange={(v) => setResolution(v as ExceptionResolution)}
                      >
                        <SelectTrigger className="h-9 text-xs">
                          <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                          <SelectItem value="handled_elsewhere" className="text-xs">
                            Handled in the owning module
                          </SelectItem>
                          {/* Only Operations' own exceptions may be closed outright. */}
                          {canResolveOutright && (
                            <>
                              <SelectItem value="fixed" className="text-xs">
                                Fixed
                              </SelectItem>
                              <SelectItem value="not_a_problem" className="text-xs">
                                Not a problem
                              </SelectItem>
                              <SelectItem value="accepted" className="text-xs">
                                Accepted as-is
                              </SelectItem>
                            </>
                          )}
                        </SelectContent>
                      </Select>
                    </div>
                    <div className="space-y-1.5">
                      <Label className="text-xs">What was done</Label>
                      <Input
                        value={reason}
                        onChange={(e) => setReason(e.target.value)}
                        placeholder="Required"
                        className="h-9 text-xs"
                      />
                    </div>
                  </div>

                  <div className="flex gap-2">
                    <Button
                      size="sm"
                      className="h-8 text-xs"
                      disabled={reason.trim().length === 0 || resolve.isPending}
                      onClick={() =>
                        resolve.mutate(
                          { id: exception.id, resolution, reason: reason.trim() },
                          {
                            onSuccess: () => {
                              toast({ title: 'Closed.' });
                              onOpenChange(false);
                            },
                            onError: () =>
                              toast({ title: 'That could not be closed.', variant: 'destructive' }),
                          },
                        )
                      }
                    >
                      Close exception
                    </Button>

                    <Button
                      size="sm"
                      variant="outline"
                      className="h-8 text-xs"
                      disabled={reason.trim().length === 0 || suppress.isPending}
                      onClick={() =>
                        suppress.mutate(
                          { id: exception.id, reason: reason.trim() },
                          {
                            onSuccess: () =>
                              toast({
                                title: 'Suppressed — it will return if it happens again.',
                              }),
                            onError: () =>
                              toast({
                                title: 'That could not be suppressed.',
                                variant: 'destructive',
                              }),
                          },
                        )
                      }
                    >
                      Suppress
                    </Button>
                  </div>
                </>
              ) : (
                <div className="space-y-1 text-sm">
                  <p className="text-muted-foreground">
                    Closed as {exception.resolution ?? 'resolved'}
                    {exception.resolved_by_name ? ` by ${exception.resolved_by_name}` : ''}.
                  </p>
                  {exception.resolution_reason && <p>{exception.resolution_reason}</p>}
                </div>
              )}
            </TabsContent>

            <TabsContent value="notes" className="pt-4">
              <Notes exceptionId={exception.id} />
            </TabsContent>

            <TabsContent value="escalations" className="pt-4">
              <Escalations exceptionId={exception.id} />
            </TabsContent>
          </Tabs>
        </div>
      )}
    </PageDrawer>
  );
}
