# 后台图片上传成功但无法预览

已确认的故障：图片保存在 `storage/app/public/categories/short/`，而容器缺少 `public/storage` 链接。默认 Filament 图片字段使用 public 磁盘，URL 为 `APP_URL/storage/...`。

服务器即时修复（保留已有图片）：

```sh
docker exec -w /var/www/html video-admin php artisan storage:link --force
```

修复后刷新后台分类编辑页。容器启动脚本也创建 public 目录并重建链接，避免重新构建后复发。存储卷中的文件不删除、不迁移。

如果仍不能预览，检查浏览器图片请求的实际 URL 和状态码：域名与协议应匹配 `https://ADMIN_DOMAIN/storage/...`。不要更改 APP_KEY 或清空存储卷。

验证启动所依赖的 Laravel 链接行为（无需数据库）：

```sh
php admin/tests/public-storage.php
```

也可用第一个参数指定已有 Composer autoload 文件。测试覆盖初次链接、重复执行、旧路径链接修复，并从 public 路径读回已有上传文件。完整 Nginx 容器与线上 HTTP 图片状态需在服务器验证。

## 前台图片路径

后台预览正常而前台失败时，Filament 存储键（例如 `shorts/covers/a.jpg`）不能直接作为前台相对地址。前端将它转为同源 `/storage/shorts/covers/a.jpg`，Web Nginx 将 `/storage/` 转发到 admin 容器读取 public 磁盘。Go API 不存放这些上传文件，不需要修改数据库路径或重建 API。

分类封面属于 `categories.cover`，显示在顶部分类入口；视频卡片使用 `videos.cover`，由后台每条短视频或长视频的编辑页维护，两者不自动互相替代。

验证命令：`node --test web/test/*.test.mjs`。本地模拟接口已验证分类键、短视频封面键经 URL 转换后可被浏览器实际加载；生产 Nginx 转发需部署后验证。
