import { get } from './client'
import type { Category, Paginated } from '@/types/models'

export const categoryApi = {
  list: (kind?: string) =>
    get<Paginated<Category>>('/categories', kind ? { kind } : undefined),
}
