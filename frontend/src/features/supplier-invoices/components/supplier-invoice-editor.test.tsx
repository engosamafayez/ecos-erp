import '@testing-library/jest-dom/vitest';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

function pathProxy(path: string): unknown {
  const target = () => path;
  return new Proxy(target, {
    get(_t, prop) {
      if (prop === Symbol.toPrimitive || prop === 'toString' || prop === 'valueOf') return () => path;
      return pathProxy(path ? `${path}.${String(prop)}` : String(prop));
    },
  });
}
vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (sel: unknown) => (typeof sel === 'function' ? String((sel as (p: unknown) => unknown)(pathProxy(''))) : String(sel)),
    i18n: { language: 'en' },
  }),
}));

vi.mock('@/hooks/use-formatter', () => ({ useFormatter: () => ({ money: (n: number) => `EGP ${n}`, currency: 'EGP' }) }));
vi.mock('@/components/ui/sheet', () => ({
  Sheet: ({ open, children }: { open: boolean; children: React.ReactNode }) => (open ? <div>{children}</div> : null),
  SheetContent: ({ children }: { children: React.ReactNode }) => <div>{children}</div>,
  SheetHeader: ({ children }: { children: React.ReactNode }) => <div>{children}</div>,
  SheetTitle: ({ children }: { children: React.ReactNode }) => <h2>{children}</h2>,
}));
// Combobox mock that sets its value on click (keyed by placeholder) so we can satisfy validation.
vi.mock('@/components/crud', () => ({
  Combobox: ({ value, onChange, placeholder }: { value: string | null; onChange: (v: string) => void; placeholder: string }) => (
    <button data-testid={`combo:${placeholder}`} onClick={() => onChange(`sel:${placeholder}`)}>{value || placeholder}</button>
  ),
}));
vi.mock('./invoice-line-editor', () => ({
  InvoiceLineEditor: ({ onLinesChange }: { onLinesChange: (l: unknown[]) => void }) => (
    <button
      data-testid="add-valid-line"
      onClick={() => onLinesChange([{ entity_type: 'product', product_id: 'prod1', product_name: 'P', quantity: '2', unit_price: '10', tax_rate: '0', line_total: '20' }])}
    >add-line</button>
  ),
}));
vi.mock('./invoice-attachments', () => ({ InvoiceAttachments: () => <div data-testid="attachments" /> }));
vi.mock('@/components/ds/use-toast', () => ({ toast: { error: vi.fn(), success: vi.fn() } }));

vi.mock('@/features/organization/context/organization-context', () => ({ useOrganizationContext: () => ({ activeCompanyId: 'c1' }) }));
vi.mock('@/features/channels/hooks/use-company-options', () => ({
  useCompanyOptions: () => ({
    // eslint-disable-next-line ecos-i18n/no-hardcoded-ui-strings -- test fixture company label
    data: [{ value: 'c1', label: 'ACME Co' }],
  }),
}));
vi.mock('@/features/purchase-orders/hooks/use-supplier-options', () => ({ useSupplierOptions: () => ({ data: [] }) }));
vi.mock('@/features/goods-receipts/hooks/use-warehouse-options', () => ({ useWarehouseOptions: () => ({ data: [] }) }));

const createMutate = vi.fn();
const uploadDocument = vi.fn();
vi.mock('@/features/supplier-invoices/services/supplier-invoices-service', () => ({
  supplierInvoicesService: { uploadDocument: (...a: unknown[]) => uploadDocument(...a) },
}));
const useSupplierInvoiceMock = vi.fn();
vi.mock('@/features/supplier-invoices/hooks/use-supplier-invoices', () => ({
  useSupplierInvoice: (...a: unknown[]) => useSupplierInvoiceMock(...a),
  useCreateSupplierInvoice: () => ({ mutateAsync: (...a: unknown[]) => createMutate(...a), isPending: false }),
  useUpdateSupplierInvoice: () => ({ mutateAsync: vi.fn(), isPending: false }),
  useValidateSupplierInvoice: () => ({ mutate: vi.fn(), isPending: false }),
}));

import { SupplierInvoiceEditor } from './supplier-invoice-editor';

function fillValidForm() {
  fireEvent.click(screen.getByTestId('combo:editor.placeholders.selectSupplier'));
  fireEvent.click(screen.getByTestId('combo:editor.placeholders.selectWarehouse'));
  fireEvent.click(screen.getByTestId('add-valid-line'));
}
function stageFile() {
  const input = document.querySelector('input[type="file"]') as HTMLInputElement;
  fireEvent.change(input, { target: { files: [new File(['x'], 'invoice.pdf', { type: 'application/pdf' })] } });
}

beforeEach(() => { createMutate.mockReset(); uploadDocument.mockReset(); useSupplierInvoiceMock.mockReset(); });

describe('SupplierInvoiceEditor — structure', () => {
  it('renders the sections, boundary note, company context and staged-attachment control (create)', () => {
    useSupplierInvoiceMock.mockReturnValue({ data: undefined });
    render(<SupplierInvoiceEditor open invoiceId={null} onOpenChange={() => {}} />);
    expect(screen.getByText('editor.sections.generalInfo')).toBeInTheDocument();
    expect(screen.getByText('editor.sections.items')).toBeInTheDocument();
    expect(screen.getByText('editor.sections.paymentSummary')).toBeInTheDocument();
    expect(screen.getByText('editor.boundaryNote')).toBeInTheDocument();
    expect(screen.queryByText(/posts directly to inventory/i)).not.toBeInTheDocument();
    expect(screen.getByText('ACME Co')).toBeInTheDocument();
    // Create-time attachment staging (§1) — no "save first" requirement.
    expect(screen.getByText('editor.attachment.select')).toBeInTheDocument();
    expect(screen.getByText('editor.payment.methodHelper')).toBeInTheDocument();
  });
});

describe('SupplierInvoiceEditor — create-time attachment (§1–§3)', () => {
  it('creates the invoice, then uploads the staged file to the new invoice id', async () => {
    useSupplierInvoiceMock.mockReturnValue({ data: undefined });
    createMutate.mockResolvedValue({ id: 'inv-new' });
    uploadDocument.mockResolvedValue({ id: 'doc1' });
    const onOpenChange = vi.fn();
    render(<SupplierInvoiceEditor open invoiceId={null} onOpenChange={onOpenChange} />);

    fillValidForm();
    stageFile();
    fireEvent.click(screen.getByText('drawer.submitCreate'));

    await waitFor(() => expect(uploadDocument).toHaveBeenCalledTimes(1));
    expect(createMutate).toHaveBeenCalledTimes(1);
    expect(uploadDocument).toHaveBeenCalledWith('inv-new', expect.any(File));
    await waitFor(() => expect(onOpenChange).toHaveBeenCalledWith(false));
  });

  it('on upload failure, Retry re-uploads WITHOUT creating a second invoice', async () => {
    useSupplierInvoiceMock.mockReturnValue({ data: undefined });
    createMutate.mockResolvedValue({ id: 'inv-new' });
    uploadDocument.mockRejectedValueOnce(new Error('upload failed')).mockResolvedValueOnce({ id: 'doc1' });
    render(<SupplierInvoiceEditor open invoiceId={null} onOpenChange={() => {}} />);

    fillValidForm();
    stageFile();
    fireEvent.click(screen.getByText('drawer.submitCreate'));

    // Partial-success state surfaces a Retry.
    const retry = await screen.findByText('editor.attachment.retry');
    expect(createMutate).toHaveBeenCalledTimes(1);
    expect(uploadDocument).toHaveBeenCalledTimes(1);

    fireEvent.click(retry);
    await waitFor(() => expect(uploadDocument).toHaveBeenCalledTimes(2));
    // No duplicate invoice — create still called exactly once.
    expect(createMutate).toHaveBeenCalledTimes(1);
    expect(uploadDocument).toHaveBeenNthCalledWith(2, 'inv-new', expect.any(File));
  });
});
