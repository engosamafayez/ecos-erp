import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';

interface DeliveryProofUploadFormProps {
  onSubmit: (input: { signature?: File | null; photos?: File[]; notes?: string }) => void;
  onCancel: () => void;
  isLoading?: boolean;
}

/**
 * Proof of delivery capture — TASK-DRIVER-APP-FINAL-CLOSURE-002 Part 2.
 *
 * ┌─ WHAT THIS DELIBERATELY DOES NOT DO ─────────────────────────────────────┐
 * │ It never sends, holds or displays a storage path. The driver picks real   │
 * │ FILES; the server stores them under a path IT generates on a private disk │
 * │ and hands back only counts and a timestamp. The old helper posted         │
 * │ client-supplied path strings — which proves nothing and is why it is gone.│
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * The accepted types mirror the server's own validation exactly (signature
 * jpg/png/pdf, photos jpg/png, 10 MB each, at most 10 photos) so the driver is told
 * before the upload rather than by a 422 after it. The server remains the authority:
 * these attributes are a courtesy, not the guard.
 */
export function DeliveryProofUploadForm({ onSubmit, onCancel, isLoading }: DeliveryProofUploadFormProps) {
  const { t } = useTranslation('driver-mobile');
  const [signature, setSignature] = useState<File | null>(null);
  const [photos, setPhotos] = useState<File[]>([]);
  const [notes, setNotes] = useState('');

  // The server refuses a proof carrying no evidence; mirror that here so the button
  // is honest about what will be accepted.
  const hasEvidence = signature !== null || photos.length > 0;

  function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    if (!hasEvidence) return;
    onSubmit({ signature, photos, notes: notes.trim() || undefined });
  }

  return (
    <form onSubmit={handleSubmit} className="space-y-4">
      <p className="text-xs text-muted-foreground">{t(($) => $.stop.deliveryProof.hint)}</p>

      <div className="space-y-1.5">
        <Label htmlFor="delivery-proof-signature">{t(($) => $.stop.deliveryProof.signatureLabel)}</Label>
        <Input
          id="delivery-proof-signature"
          type="file"
          accept="image/jpeg,image/png,application/pdf"
          onChange={(e) => setSignature(e.target.files?.[0] ?? null)}
        />
        {signature && <p className="mt-1 truncate text-xs text-muted-foreground">{signature.name}</p>}
      </div>

      <div className="space-y-1.5">
        <Label htmlFor="delivery-proof-photos">{t(($) => $.stop.deliveryProof.photosLabel)}</Label>
        <Input
          id="delivery-proof-photos"
          type="file"
          multiple
          accept="image/jpeg,image/png"
          capture="environment"
          onChange={(e) => setPhotos(Array.from(e.target.files ?? []).slice(0, 10))}
        />
        {photos.length > 0 && (
          <p className="mt-1 text-xs text-muted-foreground">
            {t(($) => $.stop.deliveryProof.photosSelected, { count: photos.length })}
          </p>
        )}
      </div>

      <div className="space-y-1.5">
        <Label htmlFor="delivery-proof-notes">{t(($) => $.stop.deliveryProof.notesLabel)}</Label>
        <Textarea
          id="delivery-proof-notes"
          value={notes}
          maxLength={2000}
          rows={2}
          onChange={(e) => setNotes(e.target.value)}
        />
      </div>

      <div className="flex gap-2">
        <Button type="button" variant="outline" onClick={onCancel} className="flex-1">
          {t(($) => $.stop.deliveryProof.cancel)}
        </Button>
        <Button type="submit" className="flex-1" disabled={isLoading || !hasEvidence}>
          {isLoading ? t(($) => $.stop.deliveryProof.uploading) : t(($) => $.stop.deliveryProof.submit)}
        </Button>
      </div>
    </form>
  );
}
