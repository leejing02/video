//
//  ChatHomeView.swift
//  首页 = 全局群聊
//

import SwiftUI

@MainActor
final class ChatViewModel: ObservableObject {
    @Published var room: ChatRoom?
    @Published var messages: [ChatMessage] = []
    @Published var loading = false
    @Published var input = ""
    @Published var error: String?

    func load() async {
        loading = true; defer { loading = false }
        do {
            let r = try await APIClient.shared.globalRoom()
            room = r
            messages = try await APIClient.shared.chatMessages(roomId: r.id)
        } catch {
            self.error = error.localizedDescription
        }
    }

    func send() async {
        guard let room, !input.trimmingCharacters(in: .whitespaces).isEmpty else { return }
        let text = input
        input = ""
        do {
            let m = try await APIClient.shared.sendMessage(roomId: room.id, content: text)
            messages.append(m)
        } catch {
            self.error = error.localizedDescription
        }
    }
}

struct ChatHomeView: View {
    @StateObject private var vm = ChatViewModel()
    @EnvironmentObject var session: SessionStore

    var body: some View {
        NavigationStack {
            VStack(spacing: 0) {
                ScrollViewReader { proxy in
                    ScrollView {
                        LazyVStack(spacing: 12) {
                            ForEach(vm.messages) { m in
                                MessageRow(message: m, isMine: m.user?.id == session.currentUser?.id)
                                    .id(m.id)
                            }
                        }
                        .padding(12)
                    }
                    .onChange(of: vm.messages.count) {
                        if let last = vm.messages.last {
                            withAnimation { proxy.scrollTo(last.id, anchor: .bottom) }
                        }
                    }
                }

                ChatInputBar(text: $vm.input) {
                    Task { await vm.send() }
                }
            }
            .navigationTitle(vm.room?.name ?? "广场")
            .task { await vm.load() }
            .refreshable { await vm.load() }
        }
    }
}

struct MessageRow: View {
    let message: ChatMessage
    let isMine: Bool

    var body: some View {
        HStack(alignment: .top, spacing: 8) {
            if isMine { Spacer(minLength: 40) }
            if !isMine { AvatarView(url: message.user?.avatar, size: 32) }
            VStack(alignment: isMine ? .trailing : .leading, spacing: 4) {
                if !isMine {
                    Text(message.user?.name ?? "—")
                        .font(.caption)
                        .foregroundStyle(.secondary)
                }
                Text(message.content)
                    .padding(.horizontal, 12).padding(.vertical, 8)
                    .background(isMine ? Color.accentColor : Color(.tertiarySystemBackground))
                    .foregroundStyle(isMine ? .white : .primary)
                    .clipShape(RoundedRectangle(cornerRadius: 14, style: .continuous))
            }
            if isMine { AvatarView(url: message.user?.avatar, size: 32) }
            if !isMine { Spacer(minLength: 40) }
        }
    }
}

struct ChatInputBar: View {
    @Binding var text: String
    let onSend: () -> Void

    var body: some View {
        HStack(spacing: 8) {
            TextField("说点什么…", text: $text, axis: .vertical)
                .lineLimit(1...4)
                .padding(10)
                .background(Color(.secondarySystemBackground))
                .clipShape(RoundedRectangle(cornerRadius: 18))
            Button(action: onSend) {
                Image(systemName: "paperplane.fill")
                    .padding(10)
                    .background(Color.accentColor)
                    .foregroundStyle(.white)
                    .clipShape(Circle())
            }
            .disabled(text.trimmingCharacters(in: .whitespaces).isEmpty)
        }
        .padding(8)
        .background(.bar)
    }
}

struct AvatarView: View {
    let url: String?
    let size: CGFloat
    var body: some View {
        AsyncImage(url: url.flatMap(URL.init(string:))) { phase in
            switch phase {
            case .success(let img): img.resizable().scaledToFill()
            default: Image(systemName: "person.crop.circle.fill").resizable().foregroundStyle(.secondary)
            }
        }
        .frame(width: size, height: size)
        .clipShape(Circle())
    }
}
