import '@testing-library/jest-dom/vitest';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';

import { Sheet, SheetContent } from '@/components/ui/sheet';
import { EmployeeLookupField } from './employee-lookup-field';

vi.mock('react-i18next', () => ({
  useTranslation: () => ({ t: (sel: unknown) => (typeof sel === 'function' ? 'label' : String(sel)) }),
}));

const employees = vi.fn();
vi.mock('@/features/iam-admin/services/users-service', () => ({
  iamDirectoriesService: { employees: (...a: unknown[]) => employees(...a) },
}));

// jsdom does not implement scrollIntoView.
Element.prototype.scrollIntoView = Element.prototype.scrollIntoView ?? (() => {});

function renderNestedInSheet() {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  const onChange = vi.fn();
  render(
    <QueryClientProvider client={queryClient}>
      <Sheet open onOpenChange={() => {}}>
        <SheetContent>
          <EmployeeLookupField value={null} onChange={onChange} />
        </SheetContent>
      </Sheet>
    </QueryClientProvider>,
  );
  return { onChange };
}

describe('EmployeeLookupField — nested inside a Sheet (the reported IAM Linked Employee defect)', () => {
  it('clicking the search input after opening focuses it', async () => {
    employees.mockResolvedValue({
      available: true,
      data: [{ id: '1', employee_number: 'EMP-0001', name: 'Osama Fayez', work_email: null, phone: null, status: 'active', company_id: 'c1', branch_id: null, department_id: null, linked_user_id: null }],
    });
    const user = userEvent.setup();
    renderNestedInSheet();
    await waitFor(() => expect(screen.getByRole('dialog')).toBeInTheDocument());
    await user.click(screen.getByRole('button', { name: /label/i }));
    const input = await screen.findByPlaceholderText('label');
    await user.click(input);
    await waitFor(() => expect(document.activeElement).toBe(input));
  });

  it('typing filters the visible employee list', async () => {
    employees.mockResolvedValue({
      available: true,
      data: [{ id: '1', employee_number: 'EMP-0001', name: 'Osama Fayez', work_email: null, phone: null, status: 'active', company_id: 'c1', branch_id: null, department_id: null, linked_user_id: null }],
    });
    const user = userEvent.setup();
    renderNestedInSheet();
    await waitFor(() => expect(screen.getByRole('dialog')).toBeInTheDocument());
    await user.click(screen.getByRole('button', { name: /label/i }));
    const input = await screen.findByPlaceholderText('label');
    await user.click(input);
    await user.keyboard('Osama');
    await waitFor(() => expect(employees).toHaveBeenCalledWith(expect.objectContaining({ q: 'Osama' })), { timeout: 500 });
  });
});
