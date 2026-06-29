export type ColumnMeta = {
  key: string;
  label: string;
  /** Cannot be toggled off in the column manager (e.g. primary key column, actions). */
  alwaysVisible?: boolean;
  /** Initial visibility when no preference is stored. Defaults to true. */
  defaultVisible?: boolean;
};

export type ColumnVisibilityState = Record<string, boolean>;
