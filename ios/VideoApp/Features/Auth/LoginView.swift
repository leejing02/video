//
//  LoginView.swift
//

import SwiftUI

struct LoginView: View {
    @EnvironmentObject var session: SessionStore
    @State private var email = "alice@example.com"
    @State private var password = "password"
    @State private var registerMode = false
    @State private var name = ""
    @State private var username = ""

    var body: some View {
        NavigationStack {
            VStack(spacing: 18) {
                Spacer()
                Text(registerMode ? "注册" : "登录")
                    .font(.largeTitle.bold())

                if registerMode {
                    TextField("昵称", text: $name).textFieldStyle(.roundedBorder)
                    TextField("用户名", text: $username)
                        .textInputAutocapitalization(.never)
                        .textFieldStyle(.roundedBorder)
                }
                TextField("邮箱", text: $email)
                    .textInputAutocapitalization(.never)
                    .keyboardType(.emailAddress)
                    .textFieldStyle(.roundedBorder)
                SecureField("密码", text: $password).textFieldStyle(.roundedBorder)

                if let err = session.errorMessage {
                    Text(err).foregroundStyle(.red).font(.footnote)
                }

                Button {
                    Task {
                        if registerMode {
                            await session.register(name: name, username: username, email: email, password: password)
                        } else {
                            await session.login(email: email, password: password)
                        }
                    }
                } label: {
                    if session.loading {
                        ProgressView().tint(.white)
                    } else {
                        Text(registerMode ? "注册" : "登录").bold()
                    }
                }
                .frame(maxWidth: .infinity, minHeight: 48)
                .background(Color.accentColor)
                .foregroundStyle(.white)
                .clipShape(RoundedRectangle(cornerRadius: 12))
                .disabled(session.loading)

                Button(registerMode ? "已有账号？登录" : "没有账号？注册") {
                    registerMode.toggle()
                    session.errorMessage = nil
                }
                .font(.footnote)

                Spacer()
            }
            .padding(24)
        }
    }
}
