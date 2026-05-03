//
//  SessionStore.swift
//  统一管理登录态、当前用户
//

import Foundation
import Combine

@MainActor
final class SessionStore: ObservableObject {
    @Published var currentUser: AppUser?
    @Published var isLoggedIn: Bool = false
    @Published var loading: Bool = false
    @Published var errorMessage: String?

    func bootstrap() async {
        guard APIClient.shared.token != nil else { return }
        do {
            currentUser = try await APIClient.shared.me()
            isLoggedIn = true
        } catch {
            APIClient.shared.token = nil
            isLoggedIn = false
        }
    }

    func login(email: String, password: String) async {
        loading = true; defer { loading = false }
        do {
            let r = try await APIClient.shared.login(email: email, password: password)
            APIClient.shared.token = r.token
            currentUser = r.user
            isLoggedIn = true
            errorMessage = nil
        } catch {
            errorMessage = error.localizedDescription
        }
    }

    func register(name: String, username: String, email: String, password: String) async {
        loading = true; defer { loading = false }
        do {
            let r = try await APIClient.shared.register(name: name, username: username, email: email, password: password)
            APIClient.shared.token = r.token
            currentUser = r.user
            isLoggedIn = true
            errorMessage = nil
        } catch {
            errorMessage = error.localizedDescription
        }
    }

    func logout() async {
        try? await APIClient.shared.logout()
        APIClient.shared.token = nil
        currentUser = nil
        isLoggedIn = false
    }
}
