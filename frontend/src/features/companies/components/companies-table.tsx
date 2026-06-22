import { ArrowDown, ArrowUp, ArrowUpDown, Building2, MoreHorizontal } from 'lucide-react';

import { Button } from '@/components/ui/button';
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Skeleton } from '@/components/ui/skeleton';
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table';
import { CompanyStatusBadge } from '@/features/companies/components/company-status-badge';
import type { Company, CompanySortField, SortDirection } from '@/features/companies/types/company';

type CompaniesTableProps = {
  items: Company[];
  isLoading: boolean;
  isError: boolean;
  sortBy: CompanySortField;
  sortDir: SortDirection;
  onSort: (field: CompanySortField) => void;
  onEdit: (company: Company) => void;
  onDelete: (company: Company) => void;
};

const SKELETON_ROWS = Array.from({ length: 5 }, (_, index) => index);
const COLUMN_COUNT = 7;

type SortableHeadProps = {
  field: CompanySortField;
  label: string;
  sortBy: CompanySortField;
  sortDir: SortDirection;
  onSort: (field: CompanySortField) => void;
};

function SortableHead({ field, label, sortBy, sortDir, onSort }: SortableHeadProps) {
  const isActive = sortBy === field;
  const Icon = isActive ? (sortDir === 'asc' ? ArrowUp : ArrowDown) : ArrowUpDown;
  return (
    <TableHead>
      <button
        type="button"
        onClick={() => onSort(field)}
        className="hover:text-foreground -ml-1 flex items-center gap-1.5 px-1"
      >
        {label}
        <Icon className="size-3.5 opacity-70" />
      </button>
    </TableHead>
  );
}

export function CompaniesTable({
  items,
  isLoading,
  isError,
  sortBy,
  sortDir,
  onSort,
  onEdit,
  onDelete,
}: CompaniesTableProps) {
  const sortProps = { sortBy, sortDir, onSort };

  return (
    <div className="rounded-lg border">
      <Table>
        <TableHeader>
          <TableRow>
            <SortableHead field="code" label="Code" {...sortProps} />
            <SortableHead field="name" label="Name" {...sortProps} />
            <TableHead>Phone</TableHead>
            <TableHead>Email</TableHead>
            <SortableHead field="country" label="Country" {...sortProps} />
            <SortableHead field="is_active" label="Status" {...sortProps} />
            <TableHead className="w-12 text-right">Actions</TableHead>
          </TableRow>
        </TableHeader>
        <TableBody>
          {isLoading ? (
            SKELETON_ROWS.map((row) => (
              <TableRow key={`skeleton-${row}`}>
                {Array.from({ length: COLUMN_COUNT }, (_, cell) => (
                  <TableCell key={cell}>
                    <Skeleton className="h-4 w-full" />
                  </TableCell>
                ))}
              </TableRow>
            ))
          ) : isError ? (
            <TableRow>
              <TableCell colSpan={COLUMN_COUNT} className="text-destructive py-10 text-center">
                Failed to load companies. Please try again.
              </TableCell>
            </TableRow>
          ) : items.length === 0 ? (
            <TableRow>
              <TableCell colSpan={COLUMN_COUNT} className="py-12 text-center">
                <div className="text-muted-foreground flex flex-col items-center gap-2">
                  <Building2 className="size-8 opacity-50" />
                  <span className="font-medium">No companies found</span>
                  <span className="text-sm">Create your first company to get started.</span>
                </div>
              </TableCell>
            </TableRow>
          ) : (
            items.map((company) => (
              <TableRow key={company.id}>
                <TableCell className="font-medium">{company.code}</TableCell>
                <TableCell>{company.name}</TableCell>
                <TableCell className="text-muted-foreground">{company.phone ?? '—'}</TableCell>
                <TableCell className="text-muted-foreground">{company.email ?? '—'}</TableCell>
                <TableCell>{company.country ?? '—'}</TableCell>
                <TableCell>
                  <CompanyStatusBadge active={company.is_active} />
                </TableCell>
                <TableCell className="text-right">
                  <DropdownMenu>
                    <DropdownMenuTrigger asChild>
                      <Button
                        variant="ghost"
                        size="icon"
                        aria-label={`Actions for ${company.name}`}
                      >
                        <MoreHorizontal className="size-4" />
                      </Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end">
                      <DropdownMenuItem onClick={() => onEdit(company)}>Edit</DropdownMenuItem>
                      <DropdownMenuItem variant="destructive" onClick={() => onDelete(company)}>
                        Delete
                      </DropdownMenuItem>
                    </DropdownMenuContent>
                  </DropdownMenu>
                </TableCell>
              </TableRow>
            ))
          )}
        </TableBody>
      </Table>
    </div>
  );
}
