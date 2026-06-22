import type { ReactNode } from 'react';
import {
  FormProvider,
  useFormContext,
  type FieldValues,
  type UseFormReturn,
} from 'react-hook-form';

type EntityFormProps<TValues extends FieldValues> = {
  form: UseFormReturn<TValues>;
  onSubmit: (values: TValues) => void;
  id?: string;
  children: ReactNode;
  className?: string;
};

/**
 * Reusable wrapper around React Hook Form. Provides the form context so field
 * components (e.g. {@link FormField}) can read validation state, and wires up
 * submit handling. The `id` lets a submit button live outside the <form>.
 */
export function EntityForm<TValues extends FieldValues>({
  form,
  onSubmit,
  id,
  children,
  className,
}: EntityFormProps<TValues>) {
  return (
    <FormProvider {...form}>
      <form id={id} onSubmit={form.handleSubmit(onSubmit)} className={className} noValidate>
        {children}
      </form>
    </FormProvider>
  );
}

type FormFieldProps = {
  name: string;
  label: string;
  required?: boolean;
  description?: string;
  children: ReactNode;
  className?: string;
};

/**
 * Field wrapper: label + control (children) + validation error. Reads the error
 * for `name` from the surrounding {@link EntityForm} context.
 */
export function FormField({
  name,
  label,
  required,
  description,
  children,
  className,
}: FormFieldProps) {
  const {
    formState: { errors },
  } = useFormContext();

  const fieldError = (errors as Record<string, { message?: unknown } | undefined>)[name];
  const message = typeof fieldError?.message === 'string' ? fieldError.message : undefined;

  return (
    <div className={`flex flex-col gap-1.5 ${className ?? ''}`}>
      <label className="text-sm font-medium">
        {label}
        {required ? <span className="text-destructive"> *</span> : null}
      </label>
      {children}
      {description && !message ? (
        <p className="text-muted-foreground text-xs">{description}</p>
      ) : null}
      {message ? <p className="text-destructive text-xs">{message}</p> : null}
    </div>
  );
}
