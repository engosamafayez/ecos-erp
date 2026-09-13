/**
 * ECOS form-field wrapper — label + control + feedback.
 *
 * This directory previously advertised itself as the "single entry point for
 * all form controls," but a UI-07 consumer audit (TASK-ECOS-V1.1-CORE-01-UI-07)
 * found every control it re-exported (EcosInput, EcosTextarea, EcosSelect,
 * EcosCombobox, EcosCheckbox, EcosSwitch, EcosDatePicker, etc., plus the
 * FormSection/FormRow/FormActions/FormDivider layout pieces) had zero real
 * consumers anywhere in `src` — every feature module already imports its
 * inputs/selects directly from `@/components/ui/*` or `@/components/ui/ecos-*`
 * instead. Those 21 zero-consumer files were deleted rather than kept as
 * unreachable duplicates. `EcosFormField`/`FormField` is the one export here
 * with real callers and is retained.
 */
export { EcosFormField, FormField, type FormFieldProps } from './form-field';
