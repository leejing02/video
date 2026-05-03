//
//  APIEndpoints.swift
//  按业务模块封装请求方法
//

import Foundation

// MARK: - Auth
extension APIClient {
    struct LoginPayload: Encodable { let email: String; let password: String }
    struct RegisterPayload: Encodable { let name, username, email, password: String }
    struct AuthResponse: Decodable { let user: AppUser; let token: String }

    func login(email: String, password: String) async throws -> AuthResponse {
        try await request("login", method: "POST",
                          body: LoginPayload(email: email, password: password),
                          as: AuthResponse.self)
    }

    func register(name: String, username: String, email: String, password: String) async throws -> AuthResponse {
        try await request("register", method: "POST",
                          body: RegisterPayload(name: name, username: username, email: email, password: password),
                          as: AuthResponse.self)
    }

    func me() async throws -> AppUser {
        try await request("me", as: AppUser.self)
    }

    func logout() async throws {
        _ = try await request("logout", method: "POST", as: EmptyResponse.self)
    }
}

// MARK: - Categories
extension APIClient {
    func categories(kind: String? = nil) async throws -> [Category] {
        var q: [String: String] = [:]
        if let kind = kind { q["kind"] = kind }
        return try await request("categories", query: q, as: [Category].self)
    }
}

// MARK: - Videos
extension APIClient {
    func videos(type: String, categoryId: Int? = nil, page: Int = 1) async throws -> Paginated<Video> {
        var q: [String: String] = ["type": type, "page": "\(page)"]
        if let cid = categoryId { q["category_id"] = "\(cid)" }
        return try await request("videos", query: q, as: Paginated<Video>.self)
    }

    func videoDetail(id: Int) async throws -> Video {
        try await request("videos/\(id)", as: Video.self)
    }

    func likeVideo(id: Int) async throws {
        struct R: Decodable { let liked: Bool; let likes: Int }
        _ = try await request("videos/\(id)/like", method: "POST", as: R.self)
    }

    func myVideos(page: Int = 1) async throws -> Paginated<Video> {
        try await request("me/videos", query: ["page": "\(page)"], as: Paginated<Video>.self)
    }
}

// MARK: - Comments
extension APIClient {
    func comments(videoId: Int, page: Int = 1) async throws -> Paginated<Comment> {
        try await request("videos/\(videoId)/comments",
                          query: ["page": "\(page)"], as: Paginated<Comment>.self)
    }

    struct CommentPayload: Encodable { let content: String; let parentId: Int? }
    func postComment(videoId: Int, content: String, parentId: Int? = nil) async throws -> Comment {
        try await request("videos/\(videoId)/comments", method: "POST",
                          body: CommentPayload(content: content, parentId: parentId),
                          as: Comment.self)
    }
}

// MARK: - Chat
extension APIClient {
    func globalRoom() async throws -> ChatRoom {
        try await request("chat/global", as: ChatRoom.self)
    }

    func chatRooms() async throws -> [ChatRoom] {
        try await request("chat/rooms", as: [ChatRoom].self)
    }

    func chatMessages(roomId: Int, beforeId: Int? = nil, limit: Int = 30) async throws -> [ChatMessage] {
        var q: [String: String] = ["limit": "\(limit)"]
        if let b = beforeId { q["before_id"] = "\(b)" }
        return try await request("chat/rooms/\(roomId)/messages", query: q, as: [ChatMessage].self)
    }

    struct SendMessagePayload: Encodable {
        let type: String?
        let content: String
        let replyToId: Int?
    }

    func sendMessage(roomId: Int, content: String, replyToId: Int? = nil) async throws -> ChatMessage {
        try await request("chat/rooms/\(roomId)/messages", method: "POST",
                          body: SendMessagePayload(type: "text", content: content, replyToId: replyToId),
                          as: ChatMessage.self)
    }
}
