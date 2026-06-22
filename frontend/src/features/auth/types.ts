/**
 * Authentication domain types (frontend).
 */
export type AuthUser = {
  id: number;
  name: string;
  email: string;
};

export type LoginCredentials = {
  email: string;
  password: string;
  remember: boolean;
};

export type LoginResponseData = {
  token: string;
  token_type: string;
  user: AuthUser;
};

export type AuthStatus = 'idle' | 'loading' | 'authenticated' | 'unauthenticated';
