import '@testing-library/jest-dom/vitest';
import { useState } from 'react';
import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

// Selector-mode i18n → resolve t($ => $.a.b.c) to the dotted path string.
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
// The server-search selector is exercised in its own test; stub it here to focus on line mechanics.
vi.mock('./product-line-select', () => ({
  ProductLineSelect: ({ entityType }: { entityType: string }) => <div data-testid={`product-select-${entityType}`} />,
}));

import { InvoiceLineEditor } from './invoice-line-editor';
import {
  EMPTY_LINE,
  computeLineTotal,
  deriveUnitPrice,
  emptyLine,
  type InvoiceLineState,
} from './invoice-line-calc';

function Harness({ initial }: { initial: InvoiceLineState[] }) {
  const [lines, setLines] = useState<InvoiceLineState[]>(initial);
  return <InvoiceLineEditor lines={lines} onLinesChange={setLines} />;
}

const LINE: InvoiceLineState = { ...EMPTY_LINE, product_id: 'p1', quantity: '10', unit_price: '20', tax_rate: '0', line_total: '200' };

describe('invoice line calculation helpers', () => {
  it('computes line total = qty × price (+tax)', () => {
    expect(computeLineTotal(10, 20, 0)).toBe(200);
    expect(computeLineTotal(10, 20, 15)).toBe(230);
  });

  it('derives unit price from a line total (inverse), guarding qty = 0', () => {
    expect(deriveUnitPrice(230, 10, 15)).toBe(20);
    expect(deriveUnitPrice(330, 10, 0)).toBe(33);
    expect(deriveUnitPrice(100, 0, 0)).toBe(0);
  });

  it('defaults VAT to 0% and stamps the line entity type (§10, §5)', () => {
    expect(EMPTY_LINE.tax_rate).toBe('0');
    expect(emptyLine('product').entity_type).toBe('product');
    expect(emptyLine('raw_material').entity_type).toBe('raw_material');
    expect(emptyLine('raw_material').tax_rate).toBe('0');
  });
});

describe('InvoiceLineEditor', () => {
  it('adds explicitly-typed Product and Raw Material lines (§4/§5), never an ambiguous "Add Item"', () => {
    render(<Harness initial={[]} />);
    expect(screen.getByText('editor.items.emptyHint')).toBeInTheDocument();
    expect(screen.queryByText('editor.items.addItem')).not.toBeInTheDocument();

    fireEvent.click(screen.getByText('editor.items.addProduct'));
    expect(screen.getAllByTestId('product-select-product').length).toBeGreaterThan(0);

    fireEvent.click(screen.getByText('editor.items.addRawMaterial'));
    expect(screen.getAllByTestId('product-select-raw_material').length).toBeGreaterThan(0);
  });

  it('renders both the desktop grid and the mobile stacked cards (§25)', () => {
    render(<Harness initial={[LINE]} />);
    expect(screen.getByTestId('lines-desktop')).toBeInTheDocument();
    expect(screen.getByTestId('lines-mobile')).toBeInTheDocument();
    expect(screen.getByLabelText('editor.items.columns.qty #1')).toBeInTheDocument();
  });

  it('editing the line total derives the unit price (§5 two-way)', () => {
    render(<Harness initial={[LINE]} />);
    fireEvent.change(screen.getByLabelText('editor.items.columns.total'), { target: { value: '330' } });
    expect((screen.getByLabelText('editor.items.columns.unitPrice') as HTMLInputElement).value).toBe('33');
  });

  it('editing the quantity recomputes the line total', () => {
    render(<Harness initial={[LINE]} />);
    fireEvent.change(screen.getByLabelText('editor.items.columns.qty'), { target: { value: '5' } });
    expect((screen.getByLabelText('editor.items.columns.total') as HTMLInputElement).value).toBe('100');
  });
});
