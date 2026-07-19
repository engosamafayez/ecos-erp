import { useState } from 'react';
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
        <Label>معرف المنتج *</Label>
        <Input
          type="number"
          value={productId}
          onChange={(e) => setProductId(e.target.value)}
          placeholder="معرف المنتج..."
          required
        />
      </div>

      <div className="space-y-1.5">
        <Label>اسم المنتج *</Label>
        <Input
          value={productName}
          onChange={(e) => setProductName(e.target.value)}
          placeholder="اسم المنتج..."
          required
        />
      </div>

      <div className="space-y-1.5">
        <Label>نوع المرتجع *</Label>
        <Select value={returnType} onValueChange={setReturnType}>
          <SelectTrigger>
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value="full">مرتجع كامل</SelectItem>
            <SelectItem value="partial">مرتجع جزئي</SelectItem>
          </SelectContent>
        </Select>
      </div>

      <div className="space-y-1.5">
        <Label>الكمية *</Label>
        <Input
          type="number"
          min="0.001"
          step="0.001"
          value={qty}
          onChange={(e) => setQty(e.target.value)}
          placeholder="0"
          required
        />
      </div>

      <div className="space-y-1.5">
        <Label>السبب</Label>
        <Textarea
          value={reason}
          onChange={(e) => setReason(e.target.value)}
          placeholder="سبب الإرجاع..."
          rows={2}
        />
      </div>

      <div className="flex gap-2">
        <Button type="button" variant="outline" onClick={onCancel} className="flex-1">
          إلغاء
        </Button>
        <Button type="submit" className="flex-1" disabled={isLoading}>
          {isLoading ? 'جارٍ الحفظ...' : 'تسجيل المرتجع'}
        </Button>
      </div>
    </form>
  );
}
