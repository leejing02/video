# Codex 项目规则

开始任何任务前先读：
- docs/UI-RULES.md（不可回退的 UI 与交互规则，含播放页黑色顶部、返回按钮、返回动画）
- docs/CURRENT-STATE.md（当前进度、待验证事项）
- docs/PROJECT.md、docs/DEPLOYMENT.md（按需）
- docs/REQUIREMENTS-2026-11-05.md（需求与验收清单）

通用要求：
- 本次任务只修改用户指定的功能，不允许破坏已经完成的功能。
- 以实际代码和 Git 状态为准；文档与代码冲突时先报告。
- 不做无关重构，不自动推送或部署；服务器由用户本人执行更新。
- 修改播放页相关功能后，确认顶部仍为 #000000、返回按钮存在且可用；无法确认时报告未验证。
- 完成一项功能后更新 docs/CURRENT-STATE.md。

Claude 的规则见 CLAUDE.md，两者内容保持一致。
