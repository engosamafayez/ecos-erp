/**
 * Centralised formatting utilities. All money/number/date helpers live here
 * so currency and locale changes propagate from a single source.
 */

/**
 * Format a monetary value. Uses toLocaleString for proper digit grouping.
 * @param amount  Numeric value to format
 * @param currency  ISO 4217 code, e.g. "EGP", "USD"
 * @param locale  BCP-47 locale tag, e.g. "en-EG"
 */
export function formatMoney(
  amount: number,
  currency = 'EGP',
  locale = 'en-US',
): string {
  const formatted = amount.toLocaleString(locale, {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  });
  return `${formatted} ${currency}`;
}

/**
 * Compact monetary format — abbreviates large values with K / M suffixes.
 * e.g. 1_500_000 → "1.5M EGP"
 */
export function formatMoneyCompact(
  amount: number,
  currency = 'EGP',
  locale = 'en-US',
): string {
  if (amount >= 1_000_000) {
    return `${(amount / 1_000_000).toLocaleString(locale, { minimumFractionDigits: 1, maximumFractionDigits: 1 })}M ${currency}`;
  }
  if (amount >= 1_000) {
    return `${(amount / 1_000).toLocaleString(locale, { minimumFractionDigits: 1, maximumFractionDigits: 1 })}K ${currency}`;
  }
  return formatMoney(amount, currency, locale);
}

/**
 * Format a plain number with locale-aware digit grouping.
 * @param value  The number to format
 * @param decimals  Number of decimal places (default 2)
 * @param locale  BCP-47 locale tag
 */
export function formatNumber(
  value: number,
  decimals = 2,
  locale = 'en-US',
): string {
  return value.toLocaleString(locale, {
    minimumFractionDigits: decimals,
    maximumFractionDigits: decimals,
  });
}

/**
 * Format an ISO date string into the company's preferred display format.
 * Falls back to ISO 8601 when no format is provided.
 * Supported tokens: YYYY, MM, DD
 */
export function formatDate(
  isoDate: string | null | undefined,
  dateFormat = 'YYYY-MM-DD',
): string {
  if (!isoDate) return '—';
  const d = new Date(isoDate);
  if (isNaN(d.getTime())) return isoDate;

  const yyyy = d.getFullYear().toString();
  const mm = String(d.getMonth() + 1).padStart(2, '0');
  const dd = String(d.getDate()).padStart(2, '0');

  return dateFormat
    .replace('YYYY', yyyy)
    .replace('MM', mm)
    .replace('DD', dd);
}
