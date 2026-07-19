import { useEffect, useState } from 'react';
import { History, Search } from 'lucide-react';
import { Input } from '@/components/ui/input';
import { materialCostService } from '@/features/cost-management/services/pricing-review-service';
import type {
  MaterialCostHistoryEntry,
  MaterialCostHistoryQuery,
} from '@/features/cost-management/types/pricing-review';

const SOURCE_LABELS: Record<string, string> = {
  manual:           'تعديل يدوي',
  purchase_invoice: 'فاتورة شراء',
};

function formatCost(n: number | null | undefined) {
  if (n == null) return '—';
  return n.toLocaleString(undefined, { minimumFractionDigits: 4, maximumFractionDigits: 4 });
}

function formatPct(n: number | null | undefined) {
  if (n == null) return '—';
  const sign = n > 0 ? '+' : '';
  return `${sign}${n.toFixed(2)}%`;
}

function formatDate(iso: string) {
  return new Date(iso).toLocaleString();
}

function DiffCell({ diff }: { diff: number }) {
  const cls = diff > 0 ? 'text-amber-500' : diff < 0 ? 'text-green-600' : 'text-muted-foreground';
  return (
    <span className={`font-mono text-sm ${cls}`}>
      {diff > 0 ? '+' : ''}{formatCost(diff)}
    </span>
  );
}

export function CostHistoryPage() {
  const [rows, setRows]       = useState<MaterialCostHistoryEntry[]>([]);
  const [loading, setLoading] = useState(true);
  const [total, setTotal]     = useState(0);
  const [page, setPage]       = useState(1);
  const [query, setQuery]     = useState<MaterialCostHistoryQuery>({ per_page: 30 });
  const [search, setSearch]   = useState('');

  useEffect(() => {
    setLoading(true);
    materialCostService
      .getGlobalHistory({ ...query, page, search: search || undefined })
      .then((res) => {
        setRows(res.data);
        setTotal(res.pagination.total);
      })
      .finally(() => setLoading(false));
  }, [query, page, search]);

  const handleSearch = (e: React.ChangeEvent<HTMLInputElement>) => {
    setSearch(e.target.value);
    setPage(1);
  };

  const handleSourceFilter = (e: React.ChangeEvent<HTMLSelectElement>) => {
    const val = e.target.value as MaterialCostHistoryQuery['source'] | '';
    setQuery((q) => ({ ...q, source: val || undefined }));
    setPage(1);
  };

  const lastPage = Math.ceil(total / (query.per_page ?? 30));

  return (
    <div className="flex flex-col gap-4 p-6">
      {/* Header */}
      <div className="flex items-center gap-3">
        <History className="size-5 text-primary" />
        <div>
          <h1 className="text-lg font-semibold">سجل تكاليف المواد</h1>
          <p className="text-sm text-muted-foreground">
            كل تغيير على تكلفة مادة، بترتيب زمني عكسي
          </p>
        </div>
      </div>

      {/* Filters */}
      <div className="flex flex-wrap items-center gap-2">
        <div className="relative">
          <Search className="absolute left-2.5 top-2.5 size-4 text-muted-foreground" />
          <Input
            type="text"
            placeholder="بحث باسم المادة أو SKU…"
            value={search}
            onChange={handleSearch}
            className="pl-8 w-64"
          />
        </div>
        <select
          className="h-9 rounded-md border border-input bg-background px-3 text-sm"
          onChange={handleSourceFilter}
          defaultValue=""
        >
          <option value="">جميع المصادر</option>
          <option value="manual">تعديل يدوي</option>
          <option value="purchase_invoice">فاتورة شراء</option>
        </select>
      </div>

      {/* Table */}
      <div className="rounded-md border overflow-auto">
        <table className="w-full text-sm">
          <thead className="border-b bg-muted/30">
            <tr>
              <th className="px-4 py-2.5 text-start font-medium text-muted-foreground">المادة</th>
              <th className="px-4 py-2.5 text-end font-medium text-muted-foreground">التكلفة السابقة</th>
              <th className="px-4 py-2.5 text-end font-medium text-muted-foreground">التكلفة الجديدة</th>
              <th className="px-4 py-2.5 text-end font-medium text-muted-foreground">الفرق</th>
              <th className="px-4 py-2.5 text-end font-medium text-muted-foreground">% التغيير</th>
              <th className="px-4 py-2.5 text-center font-medium text-muted-foreground">المصدر</th>
              <th className="px-4 py-2.5 text-end font-medium text-muted-foreground">المتأثرون</th>
              <th className="px-4 py-2.5 text-end font-medium text-muted-foreground">التاريخ</th>
            </tr>
          </thead>
          <tbody className="divide-y">
            {loading ? (
              Array.from({ length: 8 }).map((_, i) => (
                <tr key={i}>
                  {Array.from({ length: 8 }).map((__, j) => (
                    <td key={j} className="px-4 py-3">
                      <div className="h-4 animate-pulse rounded bg-muted" />
                    </td>
                  ))}
                </tr>
              ))
            ) : rows.length === 0 ? (
              <tr>
                <td colSpan={8} className="px-4 py-10 text-center text-muted-foreground">
                  لا يوجد سجل تكاليف
                </td>
              </tr>
            ) : (
              rows.map((row) => (
                <tr key={row.id} className="hover:bg-muted/20">
                  <td className="px-4 py-3">
                    <p className="font-medium">{row.product.name}</p>
                    <p className="text-xs text-muted-foreground">{row.product.sku}</p>
                  </td>
                  <td className="px-4 py-3 text-end font-mono text-sm">
                    {formatCost(row.previous_cost)}
                  </td>
                  <td className="px-4 py-3 text-end font-mono text-sm font-medium">
                    {formatCost(row.new_cost)}
                  </td>
                  <td className="px-4 py-3 text-end">
                    <DiffCell diff={row.difference} />
                  </td>
                  <td className="px-4 py-3 text-end text-sm">
                    {formatPct(row.change_pct)}
                  </td>
                  <td className="px-4 py-3 text-center">
                    <span className={`inline-flex rounded-full px-2 py-0.5 text-xs font-medium ${
                      row.source === 'manual'
                        ? 'bg-blue-500/10 text-blue-600'
                        : 'bg-purple-500/10 text-purple-600'
                    }`}>
                      {SOURCE_LABELS[row.source] ?? row.source}
                    </span>
                  </td>
                  <td className="px-4 py-3 text-end text-xs text-muted-foreground">
                    {row.affected_product_count} منتج<br />
                    {row.affected_recipe_count} وصفة
                  </td>
                  <td className="px-4 py-3 text-end text-xs text-muted-foreground whitespace-nowrap">
                    {formatDate(row.occurred_at)}
                  </td>
                </tr>
              ))
            )}
          </tbody>
        </table>
      </div>

      {/* Pagination */}
      {total > (query.per_page ?? 30) && (
        <div className="flex items-center justify-between text-sm text-muted-foreground">
          <span>{total} إدخال إجمالاً</span>
          <div className="flex items-center gap-2">
            <button
              type="button"
              disabled={page <= 1}
              onClick={() => setPage((p) => p - 1)}
              className="rounded border px-2 py-1 disabled:opacity-40"
              aria-label="الصفحة السابقة"
            >
              ‹
            </button>
            <span>{page} / {lastPage}</span>
            <button
              type="button"
              disabled={page >= lastPage}
              onClick={() => setPage((p) => p + 1)}
              className="rounded border px-2 py-1 disabled:opacity-40"
              aria-label="الصفحة التالية"
            >
              ›
            </button>
          </div>
        </div>
      )}
    </div>
  );
}
