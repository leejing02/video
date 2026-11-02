import { get, post, patch } from './client'
import type { User } from '@/types/models'

export interface AuthResponse {
  user: User
  token: string
}

export const authApi = {
  login: (email: string, password: string) =>
    post<AuthResponse>('/login', { email, password }),

  register: (data: { name: string; username: string; email: string; password: string }) =>
    post<AuthResponse>('/register', data),

  logout: () => post('/logout'),

  me: () => get<User>('/me'),

  updateMe: (data: { name?: string; bio?: string; avatar?: string; phone?: string }) =>
    patch<User>('/me', data),
}
