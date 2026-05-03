//
//  ShortVideoFeedView.swift
//  抖音式竖屏短视频流（顶部分类切换 + 全屏翻页）
//

import SwiftUI
import AVKit
import UIKit

@MainActor
final class ShortVideoVM: ObservableObject {
    @Published var categories: [Category] = []
    @Published var selectedCategory: Category?
    @Published var videos: [Video] = []
    @Published var loading = false

    func bootstrap() async {
        do {
            categories = try await APIClient.shared.categories(kind: "short")
            await load(category: nil)
        } catch { /* swallow for skeleton */ }
    }

    func load(category: Category?) async {
        loading = true; defer { loading = false }
        selectedCategory = category
        do {
            let page = try await APIClient.shared.videos(type: "short", categoryId: category?.id)
            videos = page.data
        } catch { /* skeleton */ }
    }
}

struct ShortVideoFeedView: View {
    @StateObject private var vm = ShortVideoVM()
    @State private var index: Int = 0

    var body: some View {
        ZStack(alignment: .top) {
            Color.black.ignoresSafeArea()

            if vm.videos.isEmpty {
                if vm.loading {
                    ProgressView().tint(.white)
                } else {
                    Text("暂无短视频").foregroundStyle(.white.opacity(0.6))
                }
            } else {
                TabView(selection: $index) {
                    ForEach(Array(vm.videos.enumerated()), id: \.offset) { i, v in
                        ShortVideoCell(video: v, isActive: i == index)
                            .tag(i)
                            .ignoresSafeArea()
                    }
                }
                .tabViewStyle(.page(indexDisplayMode: .never))
                .rotationEffect(.degrees(-90))
                .frame(width: UIScreen.main.bounds.height, height: UIScreen.main.bounds.width)
                .rotationEffect(.degrees(90), anchor: .topLeading)
                .offset(x: UIScreen.main.bounds.width)
                .ignoresSafeArea()
            }

            VStack {
                CategoryStrip(categories: vm.categories, selected: vm.selectedCategory) { c in
                    Task { await vm.load(category: c); index = 0 }
                }
                .padding(.top, 8)
                .background(.ultraThinMaterial)
                Spacer()
            }
        }
        .task { await vm.bootstrap() }
    }
}

struct ShortVideoCell: View {
    let video: Video
    let isActive: Bool

    var body: some View {
        ZStack(alignment: .bottomLeading) {
            ShortVideoPlayer(url: URL(string: video.url)!, isActive: isActive)
                .ignoresSafeArea()

            VStack(alignment: .leading, spacing: 6) {
                Text("@\(video.user?.username ?? "—")").font(.subheadline.bold())
                Text(video.title).font(.body)
                if let d = video.description, !d.isEmpty {
                    Text(d).font(.caption).lineLimit(2)
                }
            }
            .foregroundStyle(.white)
            .padding(.horizontal, 16)
            .padding(.bottom, 100)

            VStack(spacing: 22) {
                Spacer()
                ActionButton(icon: "heart.fill", count: video.likes)
                ActionButton(icon: "bubble.right.fill", count: video.commentsCount)
                ActionButton(icon: "arrowshape.turn.up.right.fill", count: 0)
                Spacer().frame(height: 80)
            }
            .padding(.trailing, 12)
            .frame(maxWidth: .infinity, alignment: .trailing)
        }
    }
}

struct ActionButton: View {
    let icon: String
    let count: Int
    var body: some View {
        VStack(spacing: 4) {
            Image(systemName: icon).font(.title2)
            Text("\(count)").font(.caption2)
        }
        .foregroundStyle(.white)
    }
}

/// 用 UIKit 的 AVPlayerLayer 自己控制循环播放（混合示例）
struct ShortVideoPlayer: UIViewRepresentable {
    let url: URL
    let isActive: Bool

    func makeUIView(context: Context) -> PlayerContainerView {
        let v = PlayerContainerView()
        v.configure(url: url)
        return v
    }

    func updateUIView(_ uiView: PlayerContainerView, context: Context) {
        isActive ? uiView.play() : uiView.pause()
    }

    final class PlayerContainerView: UIView {
        private let playerLayer = AVPlayerLayer()
        private var loopObserver: NSObjectProtocol?

        override init(frame: CGRect) {
            super.init(frame: frame)
            backgroundColor = .black
            layer.addSublayer(playerLayer)
            playerLayer.videoGravity = .resizeAspectFill
        }
        required init?(coder: NSCoder) { fatalError() }

        override func layoutSubviews() {
            super.layoutSubviews()
            playerLayer.frame = bounds
        }

        func configure(url: URL) {
            let item = AVPlayerItem(url: url)
            let player = AVPlayer(playerItem: item)
            player.actionAtItemEnd = .none
            playerLayer.player = player
            loopObserver = NotificationCenter.default.addObserver(
                forName: .AVPlayerItemDidPlayToEndTime,
                object: item, queue: .main
            ) { _ in
                player.seek(to: .zero); player.play()
            }
        }

        func play()  { playerLayer.player?.play() }
        func pause() { playerLayer.player?.pause() }

        deinit {
            if let o = loopObserver { NotificationCenter.default.removeObserver(o) }
        }
    }
}
