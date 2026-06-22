import { LoginForm } from '@/features/auth/components/login-form';

/**
 * Public login page. Rendered inside AuthLayout and protected by GuestRoute so
 * authenticated users can never return here.
 */
export function LoginPage() {
  return <LoginForm />;
}
