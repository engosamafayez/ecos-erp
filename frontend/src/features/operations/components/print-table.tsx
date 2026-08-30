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
 */
export function PrintTable<T>({
  title,
  subtitle,
  columns,
  rows,
  rowKey,
}: {
  title: string;
  subtitle?: ReactNode;
  columns: Array<{ header: string; cell: (row: T) => ReactNode; align?: 'start' | 'end' }>;
  rows: T[];
  rowKey: (row: T) => string;
}) {
  return (
    <div className="hidden print:block">
      <div className="mb-3">
        <h1 className="text-lg font-semibold">{title}</h1>
        {subtitle && <p className="text-sm">{subtitle}</p>}
      </div>

      <table className="w-full border-collapse text-[11px]">
        <thead>
          <tr>
            {columns.map((c) => (
              <th
                key={c.header}
                className={`border-b border-black/40 py-1 font-semibold ${
                  c.align === 'end' ? 'text-end' : 'text-start'
                }`}
              >
                {c.header}
              </th>
            ))}
          </tr>
        </thead>
        <tbody>
          {rows.map((row) => (
            <tr key={rowKey(row)}>
              {columns.map((c) => (
                <td
                  key={c.header}
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
      </table>

      <p className="mt-2 text-[10px]">{rows.length}</p>
    </div>
  );
}
