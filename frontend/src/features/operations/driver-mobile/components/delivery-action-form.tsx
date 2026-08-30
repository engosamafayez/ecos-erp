import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { Input } from '@/components/ui/input';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import type { DeliveryActionType } from '../types/driver-mobile';
import type { DeliveryActionPayload } from '../services/driver-mobile-service';
import { useFailureReasons } from '../hooks/use-driver-mobile';

interface DeliveryActionFormProps {
  actionType: DeliveryActionType;
  onSubmit: (payload: DeliveryActionPayload) => void;
  onCancel: () => void;
  isLoading?: boolean;
}

const REQUIRES_REASON: DeliveryActionType[] = ['refused', 'not_available', 'wrong_address', 'unreachable'];
const REQUIRES_DATE:   DeliveryActionType[] = ['delay'];

// Display-label keys only. The runtime option list AND fallback come from the
// backend catalogue (GET /driver/failure-reasons → FailureReason::catalogue()),
// so this union never decides which reasons exist — it only makes the localized
// label lookup type-safe, exactly like ResultKey / ReservationStatus elsewhere.
type FailureReasonKey =
  | 'customer_unavailable' | 'customer_refused' | 'customer_rescheduled' | 'no_answer'
  | 'address_not_found' | 'address_inaccessible' | 'wrong_area'
  | 'product_damaged' | 'wrong_item' | 'item_missing'
  | 'cannot_pay' | 'amount_disputed'
  | 'vehicle_breakdown' | 'time_exhausted' | 'weather';

export function DeliveryActionForm({
  actionType,
  onSubmit,
  onCancel,
  isLoading,
}: DeliveryActionFormProps) {
  const { t } = useTranslation('driver-mobile');
  const { data: failureReasons } = useFailureReasons();
  const [reason, setReason]   = useState('');
  const [notes, setNotes]     = useState('');
  const [newDate, setNewDate] = useState('');

  const reasonMissing = REQUIRES_REASON.includes(actionType) && !reason;

  function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    if (reasonMissing) return;

    // Money is frozen on the driver runtime and partial quantity has no canonical
    // writer yet, so the payload carries only what stopAction accepts and persists.
    onSubmit({
      action_type:       actionType,
      reason:            reason || undefined,
      notes:             notes || undefined,
      new_delivery_date: newDate || undefined,
    });
  }

  return (
    <form onSubmit={handleSubmit} className="space-y-4">
      <p className="font-semibold text-sm">
        {t(($) => $.actions[actionType])}
      </p>

      {/* Reason — canonical FailureReason vocabulary (backend is the source of truth) */}
      {REQUIRES_REASON.includes(actionType) && (
        <div className="space-y-1.5">
          <Label htmlFor="reason">{t(($) => $.stop.failureReason.label)}</Label>
          <Select value={reason} onValueChange={setReason}>
            <SelectTrigger id="reason">
              <SelectValue placeholder={t(($) => $.stop.failureReason.placeholder)} />
            </SelectTrigger>
            <SelectContent>
              {(failureReasons ?? []).map((opt) => (
                <SelectItem key={opt.value} value={opt.value}>
                  {t(($) => $.failureReasons[opt.value as FailureReasonKey], { defaultValue: opt.label })}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
        </div>
      )}

      {/* Reschedule date */}
      {REQUIRES_DATE.includes(actionType) && (
        <div className="space-y-1.5">
          <Label htmlFor="new-date">{t(($) => $.actionForm.newDate)}</Label>
          <Input
            id="new-date"
            type="date"
            value={newDate}
            onChange={(e) => setNewDate(e.target.value)}
            required
          />
        </div>
      )}

      {/* Notes */}
      <div className="space-y-1.5">
        <Label htmlFor="notes">{t(($) => $.actionForm.notes)}</Label>
        <Textarea
          id="notes"
          value={notes}
          onChange={(e) => setNotes(e.target.value)}
          placeholder={t(($) => $.actionForm.notesPlaceholder)}
          rows={3}
        />
      </div>

      {/* Actions */}
      <div className="flex gap-2">
        <Button type="button" variant="outline" onClick={onCancel} className="flex-1">
          {t(($) => $.actionForm.cancel)}
        </Button>
        <Button type="submit" className="flex-1" disabled={isLoading || reasonMissing}>
          {isLoading ? t(($) => $.actionForm.saving) : t(($) => $.actionForm.confirm)}
        </Button>
      </div>
    </form>
  );
}
