//
//  Models.swift
//  与后端 JSON 严格对应的数据模型
//

import Foundation

// MARK: - 用户
struct AppUser: Codable, Identifiable, Hashable {
    let id: Int
    let name: String
    let username: String
    let email: String?
    let avatar: String?
    let bio: String?
    let role: String?
    let isActive: Bool?
}

// MARK: - 分类
struct Category: Codable, Identifiable, Hashable {
    let id: Int
    let name: String
    let slug: String
    let kind: String   // long / short / both
    let icon: String?
    let cover: String?
    let sort: Int
    let isActive: Bool
}

// MARK: - 视频
struct Video: Codable, Identifiable, Hashable {
    let id: Int
    let title: String
    let description: String?
    let cover: String?
    let url: String
    let type: String   // long / short
    let duration: Int
    let views: Int
    let likes: Int
    let commentsCount: Int
    let publishedAt: Date?
    let user: AppUser?
    let category: Category?
}

// MARK: - 评论
struct Comment: Codable, Identifiable, Hashable {
    let id: Int
    let content: String
    let likes: Int
    let isPinned: Bool?
    let parentId: Int?
    let user: AppUser?
    let replies: [Comment]?
    let createdAt: Date?
}

// MARK: - 聊天
struct ChatRoom: Codable, Identifiable, Hashable {
    let id: Int
    let name: String
    let slug: String
    let kind: String   // global / group / direct
    let cover: String?
    let usersCount: Int?
    let lastMessageAt: Date?
}

struct ChatMessage: Codable, Identifiable, Hashable {
    let id: Int
    let chatRoomId: Int
    let type: String          // text / image / video / system
    let content: String
    let attachmentUrl: String?
    let replyToId: Int?
    let createdAt: Date?
    let user: AppUser?
}

// MARK: - 分页
struct Paginated<T: Codable>: Codable {
    let data: [T]
    let currentPage: Int?
    let lastPage: Int?
    let total: Int?
}
