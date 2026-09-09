import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { CheckCircle, AlertCircle } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import type { CustodyReturn } from '../types/driver-mobile';

interface CustodyReturnListProps {
  returns: CustodyReturn[];
  onConfirm?: (id: number, qty: number) => void;
  isConfirming?: boolean;
}

export function CustodyReturnList({ returns, onConfirm, isConfirming }: CustodyReturnListProps) {
  const { t } = useTranslation('driver-mobile');
  const [qtys, setQtys] = useState<Record<number, string>>({});

  if (returns.length === 0) {
    return (
      <p className="text-center text-sm text-muted-foreground py-8">
        {t(($) => $.custodyReturnPage.list.empty)}
      </p>
    );
  }

  return (
    <div className="space-y-3">
      {returns.map((item) => {
        const confirmed = item.confirmed_at !== null;
        return (
          <div key={item.id} className="rounded-lg border p-3 space-y-2">
            <div className="flex items-start justify-between gap-2">
              <div>
                <p className="font-medium text-sm">{item.custody_type}</p>
                <p className="text-xs text-muted-foreground">
                  {t(($) => $.custodyReturnPage.list.dispatchedReturned, {
                    dispatched: item.dispatched_qty,
                    returned: item.returned_qty ?? '—',
                  })}
                </p>
              </div>
              {confirmed ? (
                <CheckCircle className="h-5 w-5 text-green-600 shrink-0" />
              ) : item.driver_liable ? (
                <AlertCircle className="h-5 w-5 text-red-500 shrink-0" />
              ) : null}
            </div>

            {!confirmed && onConfirm && (
              <div className="flex gap-2">
                <Input
                  type="number"
                  min="0"
                  placeholder={t(($) => $.custodyReturnPage.list.confirmedPlaceholder)}
                  value={qtys[item.id] ?? ''}
                  onChange={(e) => setQtys((prev) => ({ ...prev, [item.id]: e.target.value }))}
                  className="h-8 text-sm"
                />
                <Button
                  size="sm"
                  disabled={isConfirming || !qtys[item.id]}
                  onClick={() => {
                    const q = parseFloat(qtys[item.id] ?? '0');
                    if (!isNaN(q)) onConfirm(item.id, q);
                  }}
                >
                  {t(($) => $.custodyReturnPage.list.confirm)}
                </Button>
              </div>
            )}

            {item.driver_liable && (
              <p className="text-xs text-red-600 font-medium">{t(($) => $.custodyReturnPage.list.liableForShortage)}</p>
            )}
          </div>
        );
      })}
    </div>
  );
}
