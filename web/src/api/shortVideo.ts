import { get, post } from './client'
import type {
  Paginated,
  ShortCategory,
  ShortVideo,
  ShortVideoComment,
} from '@/types/models'

// /short-videos/:id 详情接口的响应壳
// 对应 Go handlers.ShortVideoHandler.Show
export interface ShortVideoDetail {
  video: ShortVideo
  liked: boolean
  favorited: boolean
}

export const shortVideoApi = {
  list: (params: {
    category_id?: number
    q?: string
    page?: number
    per_page?: number
  }) =>
    get<Paginated<ShortVideo>>('/short-videos', params as Record<string, any>),

  show: (id: number) => get<ShortVideoDetail>(`/short-videos/${id}`),

  like: (id: number) =>
    post<{ liked: boolean; count: number }>(`/short-videos/${id}/like`),

  favorite: (id: number) =>
    post<{ favorited: boolean; count: number }>(`/short-videos/${id}/favorite`),

  share: (id: number, base_url: string) =>
    get<{ share_url: string }>(`/short-videos/${id}/share`, { base_url }),

  comments: (
    id: number,
    params: { page?: number; per_page?: number } = {},
  ) =>
    get<Paginated<ShortVideoComment>>(
      `/short-videos/${id}/comments`,
      params as Record<string, any>,
    ),

  createComment: (
    id: number,
    data: { content: string; parent_id?: number },
  ) => post<ShortVideoComment>(`/short-videos/${id}/comments`, data),

  categories: () => get<Paginated<ShortCategory>>('/short-categories'),

  mine: (page = 1) => get<Paginated<ShortVideo>>('/me/short-videos', { page }),
}
