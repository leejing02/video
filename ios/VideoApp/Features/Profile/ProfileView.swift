//
//  ProfileView.swift
//  个人中心：资料 / 我的视频 / 我的评论 / 登出
//

import SwiftUI

@MainActor
final class ProfileVM: ObservableObject {
    @Published var myVideos: [Video] = []
    @Published var loading = false

    func load() async {
        loading = true; defer { loading = false }
        do {
            myVideos = try await APIClient.shared.myVideos().data
        } catch { /* skeleton */ }
    }
}

struct ProfileView: View {
    @EnvironmentObject var session: SessionStore
    @StateObject private var vm = ProfileVM()

    var body: some View {
        NavigationStack {
            List {
                if let user = session.currentUser {
                    Section {
                        HStack(spacing: 14) {
                            AvatarView(url: user.avatar, size: 64)
                            VStack(alignment: .leading, spacing: 4) {
                                Text(user.name).font(.title3.bold())
                                Text("@\(user.username)").foregroundStyle(.secondary)
                                if let bio = user.bio, !bio.isEmpty {
                                    Text(bio).font(.footnote).lineLimit(2)
                                }
                            }
                            Spacer()
                        }
                        .padding(.vertical, 6)
                    }
                }

                Section("我的视频") {
                    if vm.myVideos.isEmpty && !vm.loading {
                        Text("还没有发布过视频").foregroundStyle(.secondary)
                    }
                    ForEach(vm.myVideos) { v in
                        NavigationLink(value: v) { LongVideoRow(video: v) }
                    }
                }

                Section {
                    NavigationLink("编辑资料") { Text("TODO 编辑资料表单") }
                    NavigationLink("我的评论") { Text("TODO 我的评论列表") }
                    NavigationLink("设置") { Text("TODO 设置") }
                }

                Section {
                    Button(role: .destructive) {
                        Task { await session.logout() }
                    } label: {
                        Text("退出登录").frame(maxWidth: .infinity)
                    }
                }
            }
            .navigationTitle("我的")
            .navigationDestination(for: Video.self) { VideoPlayerScreen(video: $0) }
            .task { await vm.load() }
            .refreshable { await vm.load() }
        }
    }
}
