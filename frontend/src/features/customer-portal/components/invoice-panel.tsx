import { useState } from 'react';
import { Download, Printer } from 'lucide-react';
import { useTranslation } from 'react-i18next';

import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import { customerPortalService } from '@/features/customer-portal/services/customer-portal-service';
import { useTrackInvoiceQuery } from '@/features/customer-portal/hooks/use-customer-portal';

/**
 * §13/§14/§15 — a customer-safe, print-friendly invoice view sourced entirely from
 * GET /track/order/invoice (never journal/posting internals — those keys don't exist on the
 * payload at all), plus a REAL PDF download via GET /track/order/invoice/pdf (§15 closure).
 */
export function InvoicePanel({ language }: { language: 'en' | 'ar' }) {
  const { t } = useTranslation('customer-portal');
  const invoiceQuery = useTrackInvoiceQuery(true);
  const [pdfState, setPdfState] = useState<'idle' | 'loading' | 'error'>('idle');

  const handlePrint = () => {
    window.print();
  };

  const handleDownloadPdf = async () => {
    setPdfState('loading');
    try {
      const response = await customerPortalService.invoicePdfRequest(language);
      const blobUrl = URL.createObjectURL(response.data);
      const link = document.createElement('a');
      link.href = blobUrl;
      link.download = `${invoiceQuery.data?.number ?? 'invoice'}.pdf`;
      document.body.appendChild(link);
      link.click();
      link.remove();
      URL.revokeObjectURL(blobUrl);
      setPdfState('idle');
    } catch {
      setPdfState('error');
    }
  };

  if (invoiceQuery.isLoading) {
    return (
      <div className="flex flex-col gap-2">
        <Skeleton className="h-6 w-40" />
        <Skeleton className="h-32 w-full" />
      </div>
    );
  }

  if (invoiceQuery.isError || !invoiceQuery.data) {
    return <p className="text-muted-foreground text-sm">{t(($) => $.invoice.notAvailable)}</p>;
  }

  const invoice = invoiceQuery.data;

  return (
    <div className="flex flex-col gap-4">
      <div className="flex flex-wrap items-center justify-between gap-2 print:hidden">
        <h2 className="text-lg font-semibold">{t(($) => $.invoice.heading)}</h2>
        <div className="flex gap-2">
          <Button variant="outline" size="sm" onClick={handlePrint}>
            <Printer className="size-4" />
            {t(($) => $.invoice.print)}
          </Button>
          <Button
            variant="outline"
            size="sm"
            onClick={() => void handleDownloadPdf()}
            disabled={pdfState === 'loading'}
          >
            <Download className="size-4" />
            {pdfState === 'loading'
              ? t(($) => $.invoice.downloadingPdf)
              : t(($) => $.invoice.downloadPdf)}
          </Button>
        </div>
      </div>

      {pdfState === 'error' ? (
        <p role="alert" className="text-destructive text-sm print:hidden">
          {t(($) => $.invoice.pdfError)}
        </p>
      ) : null}

      <div
        id="customer-invoice-print-area"
        className="border-border flex flex-col gap-4 rounded-md border p-4 print:border-0 print:p-0"
      >
        <div className="flex flex-wrap justify-between gap-2 text-sm">
          <div>
            <div className="text-muted-foreground">{t(($) => $.invoice.number)}</div>
            <div className="font-medium">{invoice.number}</div>
          </div>
          <div>
            <div className="text-muted-foreground">{t(($) => $.invoice.date)}</div>
            <div className="font-medium">{invoice.invoice_date ?? '—'}</div>
          </div>
          {invoice.due_date ? (
            <div>
              <div className="text-muted-foreground">{t(($) => $.invoice.dueDate)}</div>
              <div className="font-medium">{invoice.due_date}</div>
            </div>
          ) : null}
        </div>

        <div className="overflow-x-auto">
          <table className="w-full text-sm">
            <thead>
              <tr className="border-border border-b text-start">
                <th className="py-2 text-start font-medium">{t(($) => $.items.product)}</th>
                <th className="py-2 text-start font-medium">{t(($) => $.items.quantity)}</th>
                <th className="py-2 text-start font-medium">{t(($) => $.items.unitPrice)}</th>
                <th className="py-2 text-start font-medium">{t(($) => $.items.lineTotal)}</th>
              </tr>
            </thead>
            <tbody>
              {invoice.lines.map((line, index) => (
                <tr key={index} className="border-border/60 border-b last:border-0">
                  <td className="py-2">{line.description ?? '—'}</td>
                  <td className="py-2">{line.quantity}</td>
                  <td className="py-2">{line.unit_price.toFixed(2)}</td>
                  <td className="py-2">{line.net_amount.toFixed(2)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>

        <div className="ms-auto flex w-full max-w-[240px] flex-col gap-1 text-sm">
          <div className="flex justify-between">
            <span className="text-muted-foreground">{t(($) => $.financial.subtotal)}</span>
            <span>
              {invoice.subtotal.toFixed(2)} {invoice.currency}
            </span>
          </div>
          <div className="flex justify-between">
            <span className="text-muted-foreground">{t(($) => $.financial.tax)}</span>
            <span>
              {invoice.tax_total.toFixed(2)} {invoice.currency}
            </span>
          </div>
          <div className="border-border flex justify-between border-t pt-1 font-semibold">
            <span>{t(($) => $.financial.grandTotal)}</span>
            <span>
              {invoice.total.toFixed(2)} {invoice.currency}
            </span>
          </div>
          {invoice.outstanding !== null ? (
            <div className="flex justify-between">
              <span className="text-muted-foreground">{t(($) => $.financial.outstanding)}</span>
              <span>
                {invoice.outstanding.toFixed(2)} {invoice.currency}
              </span>
            </div>
          ) : null}
        </div>
      </div>
    </div>
  );
}
