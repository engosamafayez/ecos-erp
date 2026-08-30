import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import type { AddReturnPayload } from '../services/driver-mobile-service';

interface ReturnFormProps {
  orderId: number;
  onSubmit: (payload: AddReturnPayload) => void;
  onCancel: () => void;
  isLoading?: boolean;
}

export function ReturnForm({ orderId, onSubmit, onCancel, isLoading }: ReturnFormProps) {
  const { t } = useTranslation('driver-mobile');
  const [productId,   setProductId]   = useState('');
  const [productName, setProductName] = useState('');
  const [returnType,  setReturnType]  = useState('full');
  const [qty,         setQty]         = useState('');
  const [reason,      setReason]      = useState('');

  function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    onSubmit({
      order_id:     orderId,
      product_id:   parseInt(productId, 10),
      product_name: productName,
      return_type:  returnType,
      qty:          parseFloat(qty),
      reason:       reason || undefined,
    });
  }

  return (
    <form onSubmit={handleSubmit} className="space-y-4">
      <div className="space-y-1.5">
        <Label>{t(($) => $.returnForm.productId)}</Label>
        <Input
          type="number"
          value={productId}
          onChange={(e) => setProductId(e.target.value)}
          placeholder={t(($) => $.returnForm.productIdPlaceholder)}
          required
        />
      </div>

      <div className="space-y-1.5">
        <Label>{t(($) => $.returnForm.productName)}</Label>
        <Input
          value={productName}
          onChange={(e) => setProductName(e.target.value)}
          placeholder={t(($) => $.returnForm.productNamePlaceholder)}
          required
        />
      </div>

      <div className="space-y-1.5">
        <Label>{t(($) => $.returnForm.returnType)}</Label>
        <Select value={returnType} onValueChange={setReturnType}>
          <SelectTrigger>
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value="full">{t(($) => $.returnForm.full)}</SelectItem>
            <SelectItem value="partial">{t(($) => $.returnForm.partial)}</SelectItem>
          </SelectContent>
        </Select>
      </div>

      <div className="space-y-1.5">
        <Label>{t(($) => $.returnForm.quantity)}</Label>
        <Input
          type="number"
          min="0.001"
          step="0.001"
          value={qty}
          onChange={(e) => setQty(e.target.value)}
          placeholder={t(($) => $.returnForm.quantityPlaceholder)}
          required
        />
      </div>

      <div className="space-y-1.5">
        <Label>{t(($) => $.returnForm.reason)}</Label>
        <Textarea
          value={reason}
          onChange={(e) => setReason(e.target.value)}
          placeholder={t(($) => $.returnForm.reasonPlaceholder)}
          rows={2}
        />
      </div>

      <div className="flex gap-2">
        <Button type="button" variant="outline" onClick={onCancel} className="flex-1">
          {t(($) => $.returnForm.cancel)}
        </Button>
        <Button type="submit" className="flex-1" disabled={isLoading}>
          {isLoading ? t(($) => $.returnForm.saving) : t(($) => $.returnForm.submit)}
        </Button>
      </div>
    </form>
  );
}
