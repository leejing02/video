//
//  VideoAppApp.swift
//  VideoApp — 入口
//

import SwiftUI

@main
struct VideoAppApp: App {
    @StateObject private var session = SessionStore()

    var body: some Scene {
        WindowGroup {
            RootView()
                .environmentObject(session)
                .preferredColorScheme(.dark) // 视频类 App 默认黑底
        }
    }
}

/// Root：根据登录态切换 TabView / Login
struct RootView: View {
    @EnvironmentObject var session: SessionStore

    var body: some View {
        Group {
            if session.isLoggedIn {
                MainTabView()
            } else {
                LoginView()
            }
        }
        .task { await session.bootstrap() }
    }
}
