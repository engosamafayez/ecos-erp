import { describe, expect, it } from 'vitest';

import { resolvePaymentMethodLabel } from './payment-method-label';

const t = ((selector: (tree: unknown) => string) => selector({
  workspace: {
    paymentMethodLabels: {
      cod: 'Cash on Delivery',
      instapay: 'Instapay',
      mobile_wallet: 'Mobile Wallet',
      credit_card: 'Credit Card',
      bank_transfer: 'Bank Transfer',
    },
  },
})) as Parameters<typeof resolvePaymentMethodLabel>[1];

describe('resolvePaymentMethodLabel (§6)', () => {
  it('never renders a raw canonical enum value', () => {
    expect(resolvePaymentMethodLabel('cod', t)).toBe('Cash on Delivery');
    expect(resolvePaymentMethodLabel('cod', t)).not.toBe('cod');
  });

  it('resolves every one of the five canonical methods to a localized label', () => {
    expect(resolvePaymentMethodLabel('instapay', t)).toBe('Instapay');
    expect(resolvePaymentMethodLabel('mobile_wallet', t)).toBe('Mobile Wallet');
    expect(resolvePaymentMethodLabel('credit_card', t)).toBe('Credit Card');
    expect(resolvePaymentMethodLabel('bank_transfer', t)).toBe('Bank Transfer');
  });

  it('is case-insensitive against the raw stored value', () => {
    expect(resolvePaymentMethodLabel('COD', t)).toBe('Cash on Delivery');
  });

  it('falls back to the raw value for a non-canonical method rather than throwing', () => {
    expect(resolvePaymentMethodLabel('some_legacy_gateway', t)).toBe('some_legacy_gateway');
  });

  it('falls back to a dash for a null/empty method', () => {
    expect(resolvePaymentMethodLabel(null, t)).toBe('—');
    expect(resolvePaymentMethodLabel('', t)).toBe('—');
  });
});
