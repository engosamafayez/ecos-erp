import { useState } from 'react';
import axios from 'axios';
import { zodResolver } from '@hookform/resolvers/zod';
import { useForm } from 'react-hook-form';
import { useNavigate } from 'react-router-dom';
import { z } from 'zod';

import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { useAuthStore } from '@/features/auth/store/auth-store';
import { ROUTES } from '@/router/routes';

const loginSchema = z.object({
  email: z.email('Enter a valid email address.'),
  password: z.string().min(1, 'Password is required.'),
  remember: z.boolean(),
});

type LoginFormValues = z.infer<typeof loginSchema>;

/**
 * Login card: email + password + remember me, with loading and error states.
 */
export function LoginForm() {
  const navigate = useNavigate();
  const login = useAuthStore((state) => state.login);
  const [formError, setFormError] = useState<string | null>(null);

  const {
    register,
    handleSubmit,
    formState: { errors, isSubmitting },
  } = useForm<LoginFormValues>({
    resolver: zodResolver(loginSchema),
    defaultValues: { email: '', password: '', remember: false },
  });

  const onSubmit = async (values: LoginFormValues) => {
    setFormError(null);
    try {
      await login(values);
      navigate(ROUTES.dashboard, { replace: true });
    } catch (error) {
      const message =
        axios.isAxiosError(error) && typeof error.response?.data?.message === 'string'
          ? error.response.data.message
          : 'Unable to sign in. Please try again.';
      setFormError(message);
    }
  };

  return (
    <Card className="w-full max-w-sm">
      <CardHeader>
        <CardTitle>Sign in</CardTitle>
        <CardDescription>Access your ECOS ERP account.</CardDescription>
      </CardHeader>
      <CardContent>
        {formError ? (
          <Alert variant="destructive" className="mb-4">
            <AlertTitle>Sign in failed</AlertTitle>
            <AlertDescription>{formError}</AlertDescription>
          </Alert>
        ) : null}

        <form onSubmit={handleSubmit(onSubmit)} className="flex flex-col gap-4" noValidate>
          <div className="flex flex-col gap-1.5">
            <label htmlFor="email" className="text-sm font-medium">
              Email
            </label>
            <Input
              id="email"
              type="email"
              autoComplete="email"
              placeholder="you@example.com"
              {...register('email')}
            />
            {errors.email ? (
              <p className="text-destructive text-sm">{errors.email.message}</p>
            ) : null}
          </div>

          <div className="flex flex-col gap-1.5">
            <label htmlFor="password" className="text-sm font-medium">
              Password
            </label>
            <Input
              id="password"
              type="password"
              autoComplete="current-password"
              placeholder="••••••••"
              {...register('password')}
            />
            {errors.password ? (
              <p className="text-destructive text-sm">{errors.password.message}</p>
            ) : null}
          </div>

          <label className="flex items-center gap-2 text-sm">
            <input
              type="checkbox"
              className="border-input size-4 rounded"
              {...register('remember')}
            />
            Remember me
          </label>

          <Button type="submit" disabled={isSubmitting} className="w-full">
            {isSubmitting ? 'Signing in…' : 'Sign in'}
          </Button>
        </form>
      </CardContent>
    </Card>
  );
}
