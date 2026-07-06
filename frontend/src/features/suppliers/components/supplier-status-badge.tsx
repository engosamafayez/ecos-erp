import type { SupplierStatus } from '../types/supplier';

const CONFIG: Record<SupplierStatus, { label: string; className: string }> = {
  draft:     { label: 'Draft',     className: 'bg-slate-100 text-slate-600 border-slate-200' },
  active:    { label: 'Active',    className: 'bg-emerald-50 text-emerald-700 border-emerald-200' },
  preferred: { label: 'Preferred', className: 'bg-blue-50 text-blue-700 border-blue-200' },
  on_hold:   { label: 'On Hold',   className: 'bg-amber-50 text-amber-700 border-amber-200' },
  blocked:   { label: 'Blocked',   className: 'bg-red-50 text-red-700 border-red-200' },
  archived:  { label: 'Archived',  className: 'bg-muted text-muted-foreground border-border' },
};

type Props = {
  status?: SupplierStatus | null;
  /** Fallback: derive from is_active when explicit status is not provided. */
  isActive?: boolean;
};

export function SupplierStatusBadge({ status, isActive }: Props) {
  const resolved: SupplierStatus = status ?? (isActive ? 'active' : 'archived');
  const { label, className } = CONFIG[resolved];
  return (
    <span className={`inline-flex items-center rounded-full border px-2.5 py-0.5 text-xs font-medium ${className}`}>
      {label}
    </span>
  );
}
