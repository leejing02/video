import { get, post } from './client'
import type { Video, Comment, Paginated } from '@/types/models'

export const videoApi = {
  list: (params?: { type?: string; category_id?: number; page?: number; per_page?: number }) =>
    get<Paginated<Video>>('/videos', params as Record<string, any>),

  show: (id: number) => get<Video>(`/videos/${id}`),

  like: (id: number) => post<{ liked: boolean; likes: number }>(`/videos/${id}/like`),

  mine: (page = 1) => get<Paginated<Video>>('/me/videos', { page }),

  comments: (id: number, page = 1) =>
    get<Paginated<Comment>>(`/videos/${id}/comments`, { page }),

  createComment: (id: number, content: string, parent_id?: number) =>
    post<Comment>(`/videos/${id}/comments`, { content, parent_id }),
}
