import '@testing-library/jest-dom/vitest';
import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

// Selector-mode i18n → resolve t($ => $.a.b.c) to the dotted path string.
function pathProxy(path: string): unknown {
  const target = () => path;
  return new Proxy(target, {
    get(_t, prop) {
      if (prop === Symbol.toPrimitive || prop === 'toString' || prop === 'valueOf') return () => path;
      return pathProxy(path ? `${path}.${String(prop)}` : String(prop));
    },
  });
}
vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (sel: unknown) => (typeof sel === 'function' ? String((sel as (p: unknown) => unknown)(pathProxy(''))) : String(sel)),
    i18n: { language: 'en', exists: () => true },
  }),
}));

import { TaskStatusBadge } from './task-status-badge';
import type { TaskStatus } from '../types';

const STATUSES: TaskStatus[] = ['todo', 'in_progress', 'done', 'cancelled'];

describe('TaskStatusBadge', () => {
  it.each(STATUSES)('renders the translated status text for "%s"', (status) => {
    render(<TaskStatusBadge status={status} />);
    expect(screen.getByText(`tasks.status.${status}`)).toBeInTheDocument();
  });
});
