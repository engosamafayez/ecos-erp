export type QuickFilterChip = {
  key: string;
  label: string;
  count?: number;
  active?: boolean;
  onClick: () => void;
  disabled?: boolean;
};
