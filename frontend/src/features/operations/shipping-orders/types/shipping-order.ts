// TASK-ECOS-OPERATIONS-SHIPPING-ORDERS-IMPLEMENTATION-002.
// Read-model shape for the Shipping Orders page — matches
// Modules\Operations\ShippingOrders\Presentation\Http\Resources\ShippingOrderResource
// on the backend exactly. Backend-authoritative classification only (§12/§31 of the
// task) — this type never carries the raw fields a classification could be re-derived
// from client-side.

export type ShippingOrderClassification =
  | 'assigned_driver'
  | 'out_for_delivery'
  | 'delivered'
  | 'postponed'
  | 'no_answer'
  | 'cancelled';

export const SHIPPING_ORDER_CLASSIFICATIONS: readonly ShippingOrderClassification[] = [
  'assigned_driver',
  'out_for_delivery',
  'delivered',
  'postponed',
  'no_answer',
  'cancelled',
];

export type ShippingOrderPaymentStatus = 'unpaid' | 'partially_paid' | 'paid';

export type ShippingOrder = {
  id: string;
  order_number: string;
  brand: { id: string; name: string } | null;
  customer: { name: string; code: string } | null;
  order_value: number;
  payment_status: ShippingOrderPaymentStatus;
  shipping_classification: ShippingOrderClassification | null;
  shipping_company: { type: 'internal' | 'external'; name: string | null };
  driver: { name: string; code: string } | null;
  /**
   * TASK-ECOS-SHIPPING-OS-REDESIGN-003 §12 — concise execution context. Null
   * until this order's DeliveryStop has a real Trip (the LEFT JOIN in
   * ShippingOrderReadModel — see ShippingOrderResource::resolveTrip()), which
   * can be true even for a row this page shows (population only requires a
   * DeliveryStop, not yet a Trip).
   */
  trip: { id: string; number: string; stop_sequence: number; stop_total: number } | null;
  address: {
    shipping_address: string | null;
    building: string | null;
    floor: string | null;
    apartment: string | null;
    landmark: string | null;
    address_notes: string | null;
    area: string | null;
    city: string | null;
    governorate: string | null;
  };
  location: { lat: number; lng: number } | null;
};

export type ShippingOrderTabCounts = Record<'all' | ShippingOrderClassification, number>;

export type ShippingOrdersQuery = {
  page?: number;
  per_page?: number;
  classification?: ShippingOrderClassification | 'all';
  date_from?: string;
  date_to?: string;
  brand_id?: string;
  shipping_company_id?: string;
  driver_id?: string;
  payment_status?: ShippingOrderPaymentStatus;
  search?: string;
  /**
   * TASK-ECOS-SHIPPING-OS-REDESIGN-003 §15 — precise cross-surface deep link
   * ("this exact Trip's shipping orders"), not exposed as a visible filter
   * control (no raw-ID picker in the UI) — set only via a deep link's `?trip_id=`.
   */
  trip_id?: string;
};

export type ShippingOrdersResponse = {
  items: ShippingOrder[];
  meta: {
    current_page: number;
    per_page: number;
    total: number;
    last_page: number;
    counts: ShippingOrderTabCounts;
  };
};
