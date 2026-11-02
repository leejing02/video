import { get } from './client'
import type { LiveCategory, LiveStream } from '@/types/models'

export interface LiveListResponse {
  data: LiveStream[]
  meta: {
    total: number
    page: number
    per_page: number
    category_id: number
  }
}

export const liveApi = {
  categories: () => get<{ data: LiveCategory[] }>('/live-categories'),

  list: (params: { category_id?: number; page?: number; per_page?: number } = {}) =>
    get<LiveListResponse>('/live-streams', params as Record<string, any>),
}
