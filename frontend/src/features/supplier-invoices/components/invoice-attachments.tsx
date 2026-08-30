import { useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Download, Loader2, Paperclip, Trash2, Upload } from 'lucide-react';

import { toast } from '@/components/ds/use-toast';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { supplierInvoicesService } from '@/features/supplier-invoices/services/supplier-invoices-service';
import {
  useDeleteInvoiceDocument,
  useInvoiceDocuments,
  useUploadInvoiceDocument,
} from '@/features/supplier-invoices/hooks/use-supplier-invoices';
import type { SupplierInvoiceDocument } from '@/features/supplier-invoices/types/supplier-invoice';

function formatBytes(bytes: number | null): string {
  if (!bytes || bytes <= 0) return '—';
  const kb = bytes / 1024;
  return kb < 1024 ? `${Math.round(kb)} KB` : `${(kb / 1024).toFixed(1)} MB`;
}

/** §3 — the invoice attachment. Private, auth+tenant gated; downloads stream through the API. */
export function InvoiceAttachments({ invoiceId }: { invoiceId: string }) {
  const { t } = useTranslation('supplier-invoices');
  const { data: docs = [], isLoading } = useInvoiceDocuments(invoiceId);
  const upload = useUploadInvoiceDocument(invoiceId);
  const remove = useDeleteInvoiceDocument(invoiceId);
  const fileRef = useRef<HTMLInputElement>(null);
  const [downloadingId, setDownloadingId] = useState<string | null>(null);

  function onPick(event: React.ChangeEvent<HTMLInputElement>) {
    const file = event.target.files?.[0];
    event.target.value = '';
    if (!file) return;
    upload.mutate(
      { file },
      {
        onSuccess: () => toast.success(t($ => $.attachments.uploaded)),
        onError: () => toast.error(t($ => $.attachments.uploadFailed)),
      },
    );
  }

  async function download(doc: SupplierInvoiceDocument) {
    setDownloadingId(doc.id);
    try {
      const blob = await supplierInvoicesService.downloadDocument(invoiceId, doc.id);
      const url = URL.createObjectURL(blob);
      const anchor = document.createElement('a');
      anchor.href = url;
      anchor.download = doc.name;
      anchor.click();
      URL.revokeObjectURL(url);
    } catch {
      toast.error(t($ => $.attachments.downloadFailed));
    } finally {
      setDownloadingId(null);
    }
  }

  function onDelete(doc: SupplierInvoiceDocument) {
    remove.mutate(doc.id, {
      onSuccess: () => toast.success(t($ => $.attachments.deleted)),
      onError: () => toast.error(t($ => $.attachments.deleteFailed)),
    });
  }

  return (
    <div className="space-y-2">
      <div className="flex items-center justify-between">
        <Label className="text-xs font-semibold uppercase flex items-center gap-1.5">
          <Paperclip className="w-3.5 h-3.5" />
          {t($ => $.attachments.title)}
        </Label>
        <Button
          variant="outline"
          size="sm"
          className="h-7 text-xs gap-1.5"
          type="button"
          onClick={() => fileRef.current?.click()}
          disabled={upload.isPending}
        >
          {upload.isPending ? <Loader2 className="w-3 h-3 animate-spin" /> : <Upload className="w-3 h-3" />}
          {t($ => $.attachments.upload)}
        </Button>
        <input
          ref={fileRef}
          type="file"
          accept=".pdf,.jpg,.jpeg,.png,.webp"
          className="hidden"
          onChange={onPick}
        />
      </div>

      {isLoading ? (
        <div className="flex items-center gap-2 text-xs text-muted-foreground py-2">
          <Loader2 className="w-3.5 h-3.5 animate-spin" />
          {t($ => $.attachments.loading)}
        </div>
      ) : docs.length === 0 ? (
        <p className="text-xs text-muted-foreground py-2">{t($ => $.attachments.empty)}</p>
      ) : (
        <ul className="space-y-1.5">
          {docs.map((doc) => (
            <li key={doc.id} className="flex items-center gap-2 rounded-md border px-2.5 py-1.5">
              <Paperclip className="w-3.5 h-3.5 text-muted-foreground shrink-0" />
              <div className="min-w-0 flex-1">
                <p className="text-xs font-medium truncate">{doc.name}</p>
                <p className="text-[10px] text-muted-foreground">{formatBytes(doc.file_size)}</p>
              </div>
              <Button
                variant="ghost"
                size="sm"
                className="h-7 w-7 p-0"
                type="button"
                onClick={() => download(doc)}
                disabled={downloadingId === doc.id}
                aria-label={t($ => $.attachments.download)}
              >
                {downloadingId === doc.id ? <Loader2 className="w-3.5 h-3.5 animate-spin" /> : <Download className="w-3.5 h-3.5" />}
              </Button>
              <Button
                variant="ghost"
                size="sm"
                className="h-7 w-7 p-0 text-muted-foreground hover:text-destructive"
                type="button"
                onClick={() => onDelete(doc)}
                disabled={remove.isPending}
                aria-label={t($ => $.attachments.delete)}
              >
                <Trash2 className="w-3.5 h-3.5" />
              </Button>
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}
