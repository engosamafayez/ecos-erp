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

import { TaskPriorityBadge } from './task-priority-badge';
import type { TaskPriority } from '../types';

const PRIORITIES: TaskPriority[] = ['low', 'normal', 'high', 'urgent'];

describe('TaskPriorityBadge', () => {
  it.each(PRIORITIES)('renders the translated priority text for "%s"', (priority) => {
    render(<TaskPriorityBadge priority={priority} />);
    expect(screen.getByText(`tasks.priority.${priority}`)).toBeInTheDocument();
  });
});
