# iOS App — VideoApp

SwiftUI 主体 + UIKit 复杂组件（视频播放器）混合实现。

## 目录

```
VideoApp/
├── App/                     入口 / TabView
│   ├── VideoAppApp.swift
│   └── MainTabView.swift
├── Core/
│   ├── Network/             APIClient + 端点 + Reverb WebSocket
│   ├── Models/              与后端 JSON 对应的 Codable 结构
│   ├── Components/          (留空，可放公共组件)
│   └── Theme/               设计 token
└── Features/
    ├── Auth/                Login + SessionStore
    ├── Chat/                首页群聊
    ├── LongVideo/           长视频列表 + AVPlayerVC 详情
    ├── ShortVideo/          抖音式竖屏短视频流
    └── Profile/             个人中心
```

## 创建 Xcode 工程

这里只提供 Swift 源码，没有 `.xcodeproj`。建议步骤：

1. Xcode → New Project → iOS → **App**（Interface 选 SwiftUI，Language 选 Swift）
2. 项目名 `VideoApp`，路径选到 `ios/`
3. 删掉 Xcode 默认生成的 `VideoAppApp.swift` / `ContentView.swift`
4. 把当前目录下的 `VideoApp/` 整个拖进 Xcode（勾选 "Create groups"）
5. 在 `Info.plist` 里加：
   - `NSAppTransportSecurity → NSAllowsArbitraryLoads = YES`（开发期允许 http://localhost）
   - 真机调试时把 `APIClient.baseURL` 改成你电脑的局域网 IP

## 运行

模拟器跑：`APIClient.baseURL = http://localhost:8000/api` 即可。
WebSocket 连 `ws://localhost:8080`（即后端 `php artisan reverb:start`）。

## 与后端的对应

| iOS 文件                                | 后端接口 / 事件 |
|-----------------------------------------|----------------|
| `APIEndpoints.swift > login`            | `POST /api/login` |
| `APIEndpoints.swift > videos(type:)`    | `GET /api/videos?type=long\|short` |
| `APIEndpoints.swift > globalRoom`       | `GET /api/chat/global` |
| `APIEndpoints.swift > sendMessage`      | `POST /api/chat/rooms/{id}/messages` |
| `RealtimeChat.swift`                    | Reverb 广播 `message.sent` |

## TODO（继续推进时）

- 引入 `PusherSwift` 替换简易 WebSocket，支持 presence / 私有频道鉴权
- 短视频列表预加载 + 播放器复用池
- 评论二级回复 UI
- 离线缓存（`URLCache` / Core Data）
- 推送通知（APNs）
