//
//  LongVideoListView.swift
//  长视频列表（顶部分类水平滚动 + 列表）
//

import SwiftUI
import AVKit
import UIKit

@MainActor
final class LongVideoVM: ObservableObject {
    @Published var categories: [Category] = []
    @Published var selectedCategory: Category?
    @Published var videos: [Video] = []
    @Published var loading = false
    @Published var error: String?

    func bootstrap() async {
        loading = true; defer { loading = false }
        do {
            categories = try await APIClient.shared.categories(kind: "long")
            await loadVideos(category: nil)
        } catch {
            self.error = error.localizedDescription
        }
    }

    func loadVideos(category: Category?) async {
        selectedCategory = category
        do {
            let page = try await APIClient.shared.videos(type: "long", categoryId: category?.id, page: 1)
            videos = page.data
        } catch {
            self.error = error.localizedDescription
        }
    }
}

struct LongVideoListView: View {
    @StateObject private var vm = LongVideoVM()

    var body: some View {
        NavigationStack {
            VStack(spacing: 0) {
                CategoryStrip(categories: vm.categories, selected: vm.selectedCategory) { c in
                    Task { await vm.loadVideos(category: c) }
                }
                .padding(.vertical, 8)

                if vm.loading {
                    ProgressView().frame(maxWidth: .infinity, maxHeight: .infinity)
                } else {
                    List(vm.videos) { v in
                        NavigationLink(value: v) { LongVideoRow(video: v) }
                    }
                    .listStyle(.plain)
                    .refreshable { await vm.loadVideos(category: vm.selectedCategory) }
                }
            }
            .navigationTitle("长视频")
            .navigationDestination(for: Video.self) { VideoPlayerScreen(video: $0) }
            .task { await vm.bootstrap() }
        }
    }
}

struct CategoryStrip: View {
    let categories: [Category]
    let selected: Category?
    let onSelect: (Category?) -> Void

    var body: some View {
        ScrollView(.horizontal, showsIndicators: false) {
            HStack(spacing: 8) {
                Chip(text: "全部", active: selected == nil) { onSelect(nil) }
                ForEach(categories) { c in
                    Chip(text: c.name, active: selected?.id == c.id) { onSelect(c) }
                }
            }
            .padding(.horizontal, 12)
        }
    }
}

private struct Chip: View {
    let text: String; let active: Bool; let onTap: () -> Void
    var body: some View {
        Button(action: onTap) {
            Text(text)
                .font(.subheadline)
                .padding(.horizontal, 12).padding(.vertical, 6)
                .background(active ? Color.accentColor : Color(.secondarySystemBackground))
                .foregroundStyle(active ? .white : .primary)
                .clipShape(Capsule())
        }
    }
}

struct LongVideoRow: View {
    let video: Video
    var body: some View {
        HStack(spacing: 12) {
            AsyncImage(url: video.cover.flatMap(URL.init(string:))) { phase in
                switch phase {
                case .success(let img): img.resizable().scaledToFill()
                default: Color.gray.opacity(0.2)
                }
            }
            .frame(width: 130, height: 78)
            .clipShape(RoundedRectangle(cornerRadius: 8))

            VStack(alignment: .leading, spacing: 6) {
                Text(video.title).font(.subheadline.weight(.semibold)).lineLimit(2)
                Text(video.user?.name ?? "—").font(.caption).foregroundStyle(.secondary)
                HStack(spacing: 12) {
                    Label("\(video.views)", systemImage: "play.fill")
                    Label("\(video.likes)", systemImage: "heart.fill")
                    Label("\(video.commentsCount)", systemImage: "bubble.left.fill")
                }
                .font(.caption2).foregroundStyle(.secondary)
            }
            Spacer()
        }
        .padding(.vertical, 6)
    }
}

/// 详情页：UIKit 的 AVPlayerViewController 包成 SwiftUI（混合示例）
struct VideoPlayerScreen: View {
    let video: Video
    var body: some View {
        VStack(spacing: 0) {
            VideoPlayerRepresentable(url: URL(string: video.url)!)
                .frame(height: 220)
            ScrollView {
                VStack(alignment: .leading, spacing: 12) {
                    Text(video.title).font(.title3.bold())
                    Text("\(video.views) 次观看 · \(video.user?.name ?? "")")
                        .font(.caption).foregroundStyle(.secondary)
                    if let d = video.description, !d.isEmpty {
                        Text(d).font(.body)
                    }
                }
                .padding()
            }
        }
        .navigationTitle(video.title)
        .navigationBarTitleDisplayMode(.inline)
    }
}

struct VideoPlayerRepresentable: UIViewControllerRepresentable {
    let url: URL
    func makeUIViewController(context: Context) -> AVPlayerViewController {
        let vc = AVPlayerViewController()
        vc.player = AVPlayer(url: url)
        vc.player?.play()
        return vc
    }
    func updateUIViewController(_ uiViewController: AVPlayerViewController, context: Context) {}
}
