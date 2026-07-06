import { useState } from 'react';
import { BarChart3, Loader2, RefreshCw, TrendingDown } from 'lucide-react';

import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { usePreparationAnalytics } from '../hooks/use-preparation';

function fmt(n: number) {
  return n.toLocaleString(undefined, { maximumFractionDigits: 1 });
}

type SummaryCardProps = { label: string; value: string | number; sub?: string; highlight?: boolean };

function SummaryCard({ label, value, sub, highlight }: SummaryCardProps) {
  return (
    <Card className={`border shadow-none ${highlight ? 'border-amber-200 bg-amber-50' : 'border-gray-200'}`}>
      <CardContent className="p-4">
        <p className="text-xs text-gray-500 mb-1">{label}</p>
        <p className={`text-2xl font-semibold ${highlight ? 'text-amber-700' : 'text-gray-900'}`}>{value}</p>
        {sub && <p className="text-xs text-gray-400 mt-0.5">{sub}</p>}
      </CardContent>
    </Card>
  );
}

export function PreparationAnalyticsPage() {
  const today = new Date().toISOString().split('T')[0];
  const thirtyDaysAgo = new Date(Date.now() - 30 * 24 * 60 * 60 * 1000).toISOString().split('T')[0];

  const [fromDate, setFromDate] = useState(thirtyDaysAgo);
  const [toDate, setToDate]     = useState(today);
  const [applied, setApplied]   = useState({ from: thirtyDaysAgo, to: today });

  const { data, isLoading, isFetching, refetch } = usePreparationAnalytics({
    from_date: applied.from,
    to_date:   applied.to,
  });

  function apply() {
    setApplied({ from: fromDate, to: toDate });
  }

  return (
    <div className="flex flex-col gap-6 p-6">
      {/* Header */}
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-xl font-semibold text-gray-900">Preparation Analytics</h1>
          <p className="text-sm text-gray-500 mt-0.5">
            {new Date(applied.from).toLocaleDateString()} — {new Date(applied.to).toLocaleDateString()}
          </p>
        </div>
        <Button variant="outline" size="sm" onClick={() => void refetch()} disabled={isFetching} aria-label="Refresh">
          <RefreshCw className={`w-4 h-4 ${isFetching ? 'animate-spin' : ''}`} />
        </Button>
      </div>

      {/* Date range selector */}
      <div className="flex flex-wrap items-end gap-3 rounded-lg border p-4 bg-gray-50">
        <div>
          <Label className="text-xs mb-1 block">From</Label>
          <Input
            type="date"
            value={fromDate}
            onChange={(e) => setFromDate(e.target.value)}
            className="h-8 text-sm w-38"
          />
        </div>
        <div>
          <Label className="text-xs mb-1 block">To</Label>
          <Input
            type="date"
            value={toDate}
            onChange={(e) => setToDate(e.target.value)}
            className="h-8 text-sm w-38"
          />
        </div>
        <Button size="sm" className="h-8" onClick={apply} disabled={!fromDate || !toDate}>
          Apply
        </Button>
      </div>

      {isLoading ? (
        <div className="flex items-center justify-center py-20">
          <Loader2 className="w-6 h-6 animate-spin text-gray-400" />
        </div>
      ) : !data ? (
        <div className="text-center py-20 text-gray-400 text-sm">No data for the selected period.</div>
      ) : (
        <>
          {/* Summary cards */}
          <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
            <SummaryCard label="Waves Created"    value={data.summary.waves_created} />
            <SummaryCard label="Waves Completed"  value={data.summary.waves_completed} />
            <SummaryCard label="Avg Completion"   value={`${fmt(data.summary.avg_completion_pct)}%`} />
            <SummaryCard label="Avg Duration"     value={`${fmt(data.summary.avg_completion_time_minutes)}m`} sub="per wave" />
            <SummaryCard label="Waves Cancelled"  value={data.summary.waves_cancelled} />
            <SummaryCard
              label="Shortage Rate"
              value={`${fmt(data.summary.shortage_rate_pct)}%`}
              highlight={data.summary.shortage_rate_pct > 20}
            />
            <SummaryCard label="Units Prepared"   value={fmt(data.summary.total_units_prepared)} />
          </div>

          {/* Daily breakdown */}
          {data.daily.length > 0 && (
            <Card className="border shadow-none">
              <CardHeader className="pb-2">
                <CardTitle className="text-sm flex items-center gap-2">
                  <BarChart3 className="w-4 h-4" />
                  Daily Breakdown
                </CardTitle>
              </CardHeader>
              <CardContent>
                <div className="overflow-x-auto">
                  <table className="w-full text-sm">
                    <thead>
                      <tr className="border-b">
                        <th className="py-2 pr-4 text-xs font-medium text-gray-500 text-left">Date</th>
                        <th className="py-2 pr-4 text-xs font-medium text-gray-500 text-right">Waves</th>
                        <th className="py-2 pr-4 text-xs font-medium text-gray-500 text-right">Units Prepared</th>
                        <th className="py-2 text-xs font-medium text-gray-500 text-right">Avg Duration</th>
                      </tr>
                    </thead>
                    <tbody>
                      {data.daily.map((d) => (
                        <tr key={d.date} className="border-b last:border-0 hover:bg-gray-50">
                          <td className="py-2 pr-4 text-gray-700">{new Date(d.date).toLocaleDateString()}</td>
                          <td className="py-2 pr-4 text-gray-600 text-right">{d.waves}</td>
                          <td className="py-2 pr-4 text-gray-600 text-right">{fmt(d.units_prepared)}</td>
                          <td className="py-2 text-gray-600 text-right">{d.avg_minutes > 0 ? `${d.avg_minutes}m` : '—'}</td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              </CardContent>
            </Card>
          )}

          {/* Top shorted products */}
          {data.top_shorted_products.length > 0 && (
            <Card className="border shadow-none">
              <CardHeader className="pb-2">
                <CardTitle className="text-sm flex items-center gap-2">
                  <TrendingDown className="w-4 h-4 text-amber-500" />
                  Top Shorted Products
                </CardTitle>
              </CardHeader>
              <CardContent>
                <div className="space-y-2">
                  {data.top_shorted_products.map((p, i) => (
                    <div key={p.product_id} className="flex items-center justify-between py-1.5 border-b last:border-0">
                      <div className="flex items-center gap-3">
                        <span className="text-xs text-gray-400 w-5 text-right">{i + 1}.</span>
                        <span className="text-sm font-medium text-gray-800">{p.sku}</span>
                      </div>
                      <div className="flex items-center gap-4 text-xs text-gray-500">
                        <span>{p.shortage_occurrences}× shortages</span>
                        <span className="text-amber-600 font-medium">{fmt(p.avg_shortage_pct)}% avg short</span>
                      </div>
                    </div>
                  ))}
                </div>
              </CardContent>
            </Card>
          )}
        </>
      )}
    </div>
  );
}
