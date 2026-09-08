import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import { EXCEPTION_TYPES } from '../types/driver-mobile';
import type { ExceptionType } from '../types/driver-mobile';

interface ExceptionFormProps {
  onSubmit: (payload: { exception_type: ExceptionType; description: string }) => void;
  onCancel: () => void;
  isLoading?: boolean;
}

/**
 * TASK-ECOS-SHIPPING-AND-DRIVER-APP-USER-REVIEW-REMEDIATION-001: the "Photos (URLs)"
 * field this form previously had was a client-supplied free-text path — the exact
 * insecure pattern the canonical POD/payment-proof uploads deliberately moved away
 * from — and the backend (`DriverRuntimeController::raiseException()`) validates but
 * never persists `photos` anyway. Dropped rather than wired up as-is; a real photo
 * capture for exceptions is a separate, secure-upload feature, not a form-field fix.
 */
export function ExceptionForm({ onSubmit, onCancel, isLoading }: ExceptionFormProps) {
  const { t } = useTranslation('driver-mobile');
  const [exType, setExType]     = useState<ExceptionType>('damaged');
  const [description, setDesc]  = useState('');

  function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    onSubmit({ exception_type: exType, description });
  }

  return (
    <form onSubmit={handleSubmit} className="space-y-4">
      <div className="space-y-1.5">
        <Label>{t(($) => $.exceptionForm.type)}</Label>
        <Select value={exType} onValueChange={(v) => setExType(v as ExceptionType)}>
          <SelectTrigger>
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            {EXCEPTION_TYPES.map((k) => (
              <SelectItem key={k} value={k}>{t(($) => $.exceptionForm.types[k])}</SelectItem>
            ))}
          </SelectContent>
        </Select>
      </div>

      <div className="space-y-1.5">
        <Label>{t(($) => $.exceptionForm.description)}</Label>
        <Textarea
          value={description}
          onChange={(e) => setDesc(e.target.value)}
          placeholder={t(($) => $.exceptionForm.descriptionPlaceholder)}
          rows={3}
          required
        />
      </div>

      <div className="flex gap-2">
        <Button type="button" variant="outline" onClick={onCancel} className="flex-1">
          {t(($) => $.exceptionForm.cancel)}
        </Button>
        <Button type="submit" className="flex-1" disabled={isLoading}>
          {isLoading ? t(($) => $.exceptionForm.saving) : t(($) => $.exceptionForm.submit)}
        </Button>
      </div>
    </form>
  );
}
