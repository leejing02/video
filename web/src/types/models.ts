export interface User {
  id: number
  name: string
  username: string
  email: string
  avatar: string | null
  bio: string | null
  phone: string | null
  role: string
  is_active: boolean
}

export interface Category {
  id: number
  name: string
  slug: string
  kind: string
  icon: string | null
  cover: string | null
  sort: number
  is_active: boolean
}

export interface ShortCategory {
  id: number
  name: string
  slug: string
  parent_id: number | null
  icon: string | null
  cover: string | null
  description: string | null
  sort: number
  is_active: boolean
}

export interface Video {
  id: number
  user_id: number
  category_id: number
  type: 'long' | 'short'
  title: string
  description: string | null
  cover: string | null
  url: string
  duration: number
  views: number
  likes: number
  comments_count: number
  user: User | null
}

export interface ShortVideo {
  id: number
  title: string
  description: string | null
  url: string
  cover: string | null
  duration: number
  views: number
  likes: number
  favorites: number
  shares: number
  comments_count: number
  user: User | null
}

export interface ShortVideoComment {
  id: number
  user_id: number
  content: string
  user: User | null
  replies?: ShortVideoComment[]
  created_at: string
}

export interface Comment {
  id: number
  user_id: number
  video_id: number
  content: string
  user: User | null
  created_at: string
}

export interface ChatRoom {
  id: number
  name: string
  slug: string
  description: string | null
  cover: string | null
  kind: 'global' | 'group' | 'direct'
  is_active: boolean
  owner_id: number | null
  last_message: string
  unread_count: number
  member_count: number
  last_message_at: string | null
}

export interface ChatMessage {
  id: number
  chat_room_id: number
  user_id: number
  type: string
  content: string
  user: User | null
  created_at: string
}

export interface LiveCategory {
  id: number
  name: string
  slug: string
  icon: string | null
  cover: string | null
  sort: number
  is_active: boolean
}

export interface LiveStream {
  id: number
  category_id: number
  title: string
  cover: string | null
  platform: 'bilibili' | 'douyu' | string
  stream_url: string
  viewers: number
  status: 'live' | 'offline'
  created_at: string
  updated_at: string
  category: LiveCategory | null
}

export interface Paginated<T> {
  data: T[]
  meta?: {
    total: number
    per_page: number
    current_page: number
    last_page: number
  }
}
