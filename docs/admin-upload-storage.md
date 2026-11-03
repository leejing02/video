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
