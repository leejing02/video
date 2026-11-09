import { get, post } from './client'
import type { ChatRoom, ChatMessage } from '@/types/models'

export const chatApi = {
  globalRoom: () => get<ChatRoom>('/chat/global'),

  myRooms: () => get<ChatRoom[]>('/chat/rooms'),

  messages: (roomId: number, beforeId?: number, limit = 30) =>
    get<ChatMessage[]>(`/chat/rooms/${roomId}/messages`, {
      before_id: beforeId,
      limit,
    }),

  messagesAfter: (roomId: number, afterId: number, limit = 100) =>
    get<ChatMessage[]>(`/chat/rooms/${roomId}/messages`, { after_id: afterId, limit }),

  direct: (userId: number, contextRoomId: number) =>
    post<ChatRoom>('/chat/direct', { user_id: userId, context_room_id: contextRoomId }),

  send: (roomId: number, content: string, replyToId?: number) =>
    post<ChatMessage>(`/chat/rooms/${roomId}/messages`, {
      type: 'text',
      content,
      reply_to_id: replyToId,
    }),

  join: (roomId: number) => post(`/chat/rooms/${roomId}/join`),

  markRead: (roomId: number) => post(`/chat/rooms/${roomId}/read`),

  // Group chats are 全体禁言 except for users an admin allowed to speak.
  permissions: () => get<{ can_speak: boolean }>('/chat/permissions'),
}
