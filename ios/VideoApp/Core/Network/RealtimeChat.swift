//
//  RealtimeChat.swift
//  对接 Go 后端的极简 WebSocket：ws://host:port/ws/chat?room_id=&token=
//
//  服务端推送的帧格式：{ "event": "message.sent", "data": <ChatMessage JSON> }
//

import Foundation

protocol RealtimeChatDelegate: AnyObject {
    func realtime(_ client: RealtimeChat, didReceive message: ChatMessage)
    func realtime(_ client: RealtimeChat, didError error: Error)
}

final class RealtimeChat {
    weak var delegate: RealtimeChatDelegate?

    private var task: URLSessionWebSocketTask?
    private let session = URLSession(configuration: .default)
    private let baseHost: String
    private let basePort: Int
    private let scheme: String
    private(set) var roomId: Int = 0

    /// 与 APIClient.baseURL 保持一致即可
    init(host: String = "localhost", port: Int = 8000, scheme: String = "http") {
        self.baseHost = host
        self.basePort = port
        self.scheme = scheme
    }

    /// 连接到指定聊天室
    func connect(roomId: Int, token: String) {
        self.roomId = roomId
        let ws = scheme == "https" ? "wss" : "ws"
        guard var components = URLComponents(string: "\(ws)://\(baseHost):\(basePort)/ws/chat") else { return }
        components.queryItems = [
            URLQueryItem(name: "room_id", value: "\(roomId)"),
            URLQueryItem(name: "token",   value: token),
        ]
        guard let url = components.url else { return }

        task = session.webSocketTask(with: url)
        task?.resume()
        receive()
    }

    func disconnect() {
        task?.cancel(with: .goingAway, reason: nil)
        task = nil
    }

    // MARK: - Private
    private struct Envelope: Decodable {
        let event: String
        let data:  ChatMessage?
    }

    private func receive() {
        task?.receive { [weak self] result in
            guard let self else { return }
            switch result {
            case .failure(let err):
                self.delegate?.realtime(self, didError: err)
            case .success(let msg):
                if case .string(let text) = msg, let data = text.data(using: .utf8) {
                    self.handle(data: data)
                }
                self.receive()  // 继续监听
            }
        }
    }

    private func handle(data: Data) {
        let dec = JSONDecoder()
        dec.keyDecodingStrategy = .convertFromSnakeCase
        dec.dateDecodingStrategy = .iso8601
        guard let env = try? dec.decode(Envelope.self, from: data) else { return }
        if env.event == "message.sent", let m = env.data {
            DispatchQueue.main.async { self.delegate?.realtime(self, didReceive: m) }
        }
    }
}
