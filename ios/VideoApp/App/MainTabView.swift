//
//  MainTabView.swift
//  四个主界面：群聊 / 长视频 / 短视频 / 个人中心
//

import SwiftUI

struct MainTabView: View {
    @State private var selection: Tab = .chat

    enum Tab: Int { case chat, long, short, profile }

    var body: some View {
        TabView(selection: $selection) {
            ChatHomeView()
                .tabItem { Label("群聊", systemImage: "bubble.left.and.bubble.right.fill") }
                .tag(Tab.chat)

            LongVideoListView()
                .tabItem { Label("长视频", systemImage: "play.tv.fill") }
                .tag(Tab.long)

            ShortVideoFeedView()
                .tabItem { Label("短视频", systemImage: "play.rectangle.on.rectangle.fill") }
                .tag(Tab.short)

            ProfileView()
                .tabItem { Label("我的", systemImage: "person.crop.circle.fill") }
                .tag(Tab.profile)
        }
        .tint(.accentColor)
    }
}
