import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Input } from '@/components/ui/input';

interface PaymentProofUploadFormProps {
  onSubmit: (file: File) => void;
  onCancel: () => void;
  isLoading?: boolean;
}

/**
 * Payment-transfer proof upload (TASK-DRIVER-WAVE-2-PHASE-1, Part C).
 *
 * The driver may only UPLOAD a proof file (a bank-transfer receipt image / PDF)
 * into the canonical `payment_proofs` store. There is deliberately NO verify /
 * approve / settle control here — the driver cannot change any financial state;
 * an operator with `sales.orders.proof_verify` does that on a separate surface.
 */
export function PaymentProofUploadForm({ onSubmit, onCancel, isLoading }: PaymentProofUploadFormProps) {
  const { t } = useTranslation('driver-mobile');
  const [file, setFile] = useState<File | null>(null);

  function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    if (!file) return;
    onSubmit(file);
  }

  return (
    <form onSubmit={handleSubmit} className="space-y-4">
      <p className="text-xs text-muted-foreground">{t(($) => $.stop.paymentProof.hint)}</p>

      <div className="space-y-1.5">
        <Label htmlFor="proof-file">{t(($) => $.stop.paymentProof.fileLabel)}</Label>
        <Input
          id="proof-file"
          type="file"
          accept="image/jpeg,image/png,application/pdf"
          capture="environment"
          onChange={(e) => setFile(e.target.files?.[0] ?? null)}
        />
        {file && <p className="text-xs text-muted-foreground truncate mt-1">{file.name}</p>}
      </div>

      <div className="flex gap-2">
        <Button type="button" variant="outline" onClick={onCancel} className="flex-1">
          {t(($) => $.stop.paymentProof.cancel)}
        </Button>
        <Button type="submit" className="flex-1" disabled={isLoading || !file}>
          {isLoading ? t(($) => $.stop.paymentProof.uploading) : t(($) => $.stop.paymentProof.submit)}
        </Button>
      </div>
    </form>
  );
}
