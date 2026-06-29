import type { ReactNode } from 'react';

import { EntityDrawer } from '@/components/crud/entity-drawer';
import type { PageDrawerSize } from '../types';

const SIZE_CLASS: Record<PageDrawerSize, string> = {
  sm:   'sm:max-w-sm',
  md:   'sm:max-w-md',
  lg:   'sm:max-w-lg',
  xl:   'sm:max-w-xl',
  full: 'sm:max-w-none sm:w-full',
};

type PageDrawerProps = {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  title: string;
  description?: string;
  /** Drawer width preset. Default: 'xl' (576 px) for forms, 'lg' for detail views. */
  size?: PageDrawerSize;
  side?: 'left' | 'right';
  children: ReactNode;
  footer?: ReactNode;
};

/**
 * Standardized drawer shell for ERP pages.
 * Extends EntityDrawer with explicit size presets so every page uses the
 * same width vocabulary instead of ad-hoc max-w values.
 *
 * Usage:
 *   <PageDrawer open={open} onOpenChange={setOpen} title="New Order" size="xl">
 *     <OrderForm ... />
 *   </PageDrawer>
 */
export function PageDrawer({ size = 'xl', ...props }: PageDrawerProps) {
  return <EntityDrawer {...props} className={SIZE_CLASS[size]} />;
}
