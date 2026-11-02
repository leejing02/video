import { get, post } from './client'
import type { ChatRoom, ChatMessage, Paginated } from '@/types/models'

export const chatApi = {
  globalRoom: () => get<ChatRoom>('/chat/global'),

  myRooms: () => get<ChatRoom[]>('/chat/rooms'),

  messages: (roomId: number, beforeId?: number, limit = 30) =>
    get<Paginated<ChatMessage>>(`/chat/rooms/${roomId}/messages`, {
      before_id: beforeId,
      limit,
    }),

  send: (roomId: number, content: string, replyToId?: number) =>
    post<ChatMessage>(`/chat/rooms/${roomId}/messages`, {
      type: 'text',
      content,
      reply_to_id: replyToId,
    }),

  join: (roomId: number) => post(`/chat/rooms/${roomId}/join`),

  markRead: (roomId: number) => post(`/chat/rooms/${roomId}/read`),
}
