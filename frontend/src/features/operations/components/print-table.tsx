import type { ReactNode } from 'react';

/**
 * A plain table rendered ONLY when printing.
 *
 * Why this exists instead of printing the grid: UniversalDataGrid renders two
 * variants — `block lg:hidden` (mobile cards) and `hidden lg:block` (desktop
 * table) — both inside `overflow-hidden` wrappers. The `lg:` breakpoint does not
 * resolve against the print viewport the way it does on screen, so the desktop
 * table stays `hidden` and the output came out as a header with no body.
 *
 * Rendering a dedicated print table sidesteps that without touching
 * UniversalDataGrid globally, which other screens depend on.
 *
 * It also takes its columns as an explicit list, so print is independent of the
 * Columns Manager: hiding a column on screen never removes it from the printout.
 *
 * `renderDetail` (opt-in) renders a second, full-width row under each record —
 * for content (long addresses, item lists, notes) that reads better wrapped
 * across the whole page width than squeezed into its own narrow column. When
 * supplied, each record's pair of rows is grouped into its own `<tbody>` with
 * `break-inside: avoid` so the detail line can't be stranded alone across a
 * page break. Callers that don't pass it keep the original single-`<tbody>`,
 * one-row-per-record layout completely unchanged.
 */
export function PrintTable<T>({
  title,
  subtitle,
  columns,
  rows,
  rowKey,
  renderDetail,
  countLabel,
  className,
}: {
  title: string;
  subtitle?: ReactNode;
  columns: Array<{ header: string; cell: (row: T) => ReactNode; align?: 'start' | 'end'; width?: string }>;
  rows: T[];
  rowKey: (row: T) => string;
  /** Extra full-width row rendered under each record — see doc comment above. */
  renderDetail?: (row: T) => ReactNode;
  /** Pluralized noun for the footer count line, e.g. "orders". Omitted keeps the bare count (legacy behavior). */
  countLabel?: string;
  /** Extra class(es) merged onto the print wrapper — e.g. to opt into a named
   *  `@page` rule (CSS's `page` property) for landscape/margins scoped to just
   *  this table, without changing the page size for every other PrintTable. */
  className?: string;
}) {
  const hasWidths = columns.some((c) => c.width);

  return (
    <div className={['hidden print:block', className].filter(Boolean).join(' ')}>
      <div className="mb-3">
        <h1 className="text-lg font-semibold">{title}</h1>
        {subtitle && <p className="text-sm">{subtitle}</p>}
      </div>

      <table
        className="w-full border-collapse text-[11px]"
        style={hasWidths ? { tableLayout: 'fixed' } : undefined}
      >
        {hasWidths ? (
          <colgroup>
            {columns.map((c) => (
              <col key={c.header} style={{ width: c.width }} />
            ))}
          </colgroup>
        ) : null}
        <thead>
          <tr>
            {columns.map((c) => (
              <th
                key={c.header}
                className={`border-b-2 border-black/60 py-1.5 font-semibold ${
                  c.align === 'end' ? 'text-end' : 'text-start'
                }`}
              >
                {c.header}
              </th>
            ))}
          </tr>
        </thead>
        {renderDetail ? (
          rows.map((row) => (
            <tbody key={rowKey(row)} style={{ breakInside: 'avoid' }}>
              <tr>
                {columns.map((c) => (
                  <td
                    key={c.header}
                    dir="auto"
                    className={`pt-1.5 align-top ${c.align === 'end' ? 'text-end tabular-nums' : 'text-start'}`}
                  >
                    {c.cell(row)}
                  </td>
                ))}
              </tr>
              <tr>
                <td
                  colSpan={columns.length}
                  dir="auto"
                  className="border-b border-black/10 pb-1.5 pt-0.5 align-top text-[10px] text-black/70"
                >
                  {renderDetail(row)}
                </td>
              </tr>
            </tbody>
          ))
        ) : (
          <tbody>
            {rows.map((row) => (
              <tr key={rowKey(row)}>
                {columns.map((c) => (
                  <td
                    key={c.header}
                    dir="auto"
                    className={`border-b border-black/10 py-1 align-top ${
                      c.align === 'end' ? 'text-end tabular-nums' : 'text-start'
                    }`}
                  >
                    {c.cell(row)}
                  </td>
                ))}
              </tr>
            ))}
          </tbody>
        )}
      </table>

      <p className="mt-2 text-[10px]">{countLabel ? `${rows.length} ${countLabel}` : rows.length}</p>
    </div>
  );
}
