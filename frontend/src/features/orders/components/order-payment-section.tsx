import { useRef, useState } from 'react';
import { Controller, useFormContext } from 'react-hook-form';
import { ExternalLink, FileText, Loader2, Upload, X } from 'lucide-react';
import axios from 'axios';

import { FormField } from '@/components/crud';
import { Button } from '@/components/ui/button';
import type { ManualOrderFormValues } from '@/features/orders/components/order-form-schema';
import { getMediaUrl } from '@/lib/media';

const PAYMENT_METHODS = [
  { value: 'cod', label: 'Cash on Delivery' },
  { value: 'instapay', label: 'Instapay' },
  { value: 'mobile_wallet', label: 'Mobile Wallet' },
  { value: 'credit_card', label: 'Credit Card' },
] as const;

const ACCEPTED = 'image/jpeg,image/jpg,image/png,image/webp,image/gif,application/pdf';
const MAX_MB = 10;

export function OrderPaymentSection() {
  const { control, watch, setValue, formState: { errors } } = useFormContext<ManualOrderFormValues>();
  const [uploading, setUploading] = useState(false);
  const [uploadError, setUploadError] = useState<string | null>(null);
  const fileRef = useRef<HTMLInputElement>(null);

  const paymentMethod = watch('payment_method_manual');
  const proofPath = watch('payment_proof_path');
  const requiresProof = paymentMethod && paymentMethod !== 'cod';

  const handleFileChange = async (e: React.ChangeEvent<HTMLInputElement>) => {
    const file = e.target.files?.[0];
    if (!file) return;

    if (file.size > MAX_MB * 1024 * 1024) {
      setUploadError(`File must be under ${MAX_MB} MB.`);
      return;
    }

    setUploadError(null);
    setUploading(true);

    const formData = new FormData();
    formData.append('file', file);
    formData.append('context', 'order-proof');

    try {
      const res = await axios.post<{ data: { path: string; url: string } }>('/api/media/upload', formData);
      setValue('payment_proof_path', res.data.data.path, { shouldValidate: true });
    } catch {
      setUploadError('Upload failed. Please try again.');
    } finally {
      setUploading(false);
      if (fileRef.current) fileRef.current.value = '';
    }
  };

  const handleClear = () => {
    setValue('payment_proof_path', undefined);
    setUploadError(null);
  };

  const proofUrl = proofPath ? getMediaUrl(proofPath) : null;
  const isPdf = proofPath?.toLowerCase().endsWith('.pdf');

  return (
    <div className="grid gap-4 sm:grid-cols-2">
      <div className="sm:col-span-2">
        <FormField name="payment_method_manual" label="Payment Method">
          <Controller
            control={control}
            name="payment_method_manual"
            render={({ field }) => (
              <div className="grid grid-cols-2 gap-2 sm:grid-cols-4">
                {PAYMENT_METHODS.map((m) => (
                  <label
                    key={m.value}
                    className={`flex cursor-pointer items-center justify-center rounded-md border px-3 py-2 text-sm transition-colors ${
                      field.value === m.value
                        ? 'border-primary bg-primary/10 font-medium text-primary'
                        : 'border-input hover:bg-muted/50'
                    }`}
                  >
                    <input
                      type="radio"
                      className="sr-only"
                      value={m.value}
                      checked={field.value === m.value}
                      onChange={() => field.onChange(m.value)}
                    />
                    {m.label}
                  </label>
                ))}
              </div>
            )}
          />
        </FormField>
      </div>

      {requiresProof && (
        <div className="sm:col-span-2">
          <FormField name="payment_proof_path" label="Payment Proof" required>
            {proofPath ? (
              <div className="flex items-center gap-2 rounded-md border px-3 py-2 bg-muted/40">
                {isPdf ? (
                  <FileText className="size-4 text-muted-foreground shrink-0" />
                ) : (
                  proofUrl && (
                    <img src={proofUrl} alt="proof" className="size-8 rounded object-cover shrink-0" />
                  )
                )}
                <span className="flex-1 truncate text-sm text-muted-foreground">{proofPath.split('/').pop()}</span>
                {proofUrl && (
                  <a href={proofUrl} target="_blank" rel="noopener noreferrer" className="shrink-0">
                    <ExternalLink className="size-4 text-muted-foreground hover:text-foreground" />
                  </a>
                )}
                <Button type="button" variant="ghost" size="icon" className="size-6 shrink-0" onClick={handleClear}>
                  <X className="size-3" />
                </Button>
              </div>
            ) : (
              <div
                className="flex cursor-pointer flex-col items-center justify-center gap-2 rounded-md border border-dashed px-4 py-6 text-center hover:bg-muted/30 transition-colors"
                onClick={() => fileRef.current?.click()}
              >
                {uploading ? (
                  <Loader2 className="size-5 animate-spin text-muted-foreground" />
                ) : (
                  <Upload className="size-5 text-muted-foreground" />
                )}
                <p className="text-sm text-muted-foreground">
                  {uploading ? 'Uploading…' : 'Click to upload image or PDF'}
                </p>
                <p className="text-xs text-muted-foreground">Max {MAX_MB} MB · JPEG, PNG, WebP, GIF, PDF</p>
              </div>
            )}
            <input
              ref={fileRef}
              type="file"
              accept={ACCEPTED}
              className="sr-only"
              onChange={handleFileChange}
              disabled={uploading}
            />
            {uploadError && <p className="mt-1 text-xs text-destructive">{uploadError}</p>}
            {errors.payment_proof_path && (
              <p className="mt-1 text-xs text-destructive">{errors.payment_proof_path.message}</p>
            )}
            <p className="mt-1 text-xs text-muted-foreground">
              Payment proof is required for non-COD orders.
            </p>
          </FormField>
        </div>
      )}
    </div>
  );
}
