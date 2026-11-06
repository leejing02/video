// video-api server entrypoint
//
// 启动顺序：load .env → 连库 → 起 ws Hub → 注册 gin 路由 → ListenAndServe
package main

import (
	"context"
	"log"
	"os"
	"strconv"

	"github.com/gin-contrib/cors"
	"github.com/gin-gonic/gin"
	"github.com/yourname/video-api/internal/config"
	"github.com/yourname/video-api/internal/db"
	"github.com/yourname/video-api/internal/handlers"
	"github.com/yourname/video-api/internal/middleware"
	"github.com/yourname/video-api/internal/repos"
	"github.com/yourname/video-api/internal/videocache"
	"github.com/yourname/video-api/internal/ws"
)

func main() {
	cfg := config.Load()

	// MySQL（与 Laravel admin 共用同一个库）
	dbx := db.MustOpen(cfg.DSN())
	defer dbx.Close()

	// 仓储
	userRepo := repos.NewUserRepo(dbx)
	catRepo := repos.NewCategoryRepo(dbx)
	vidRepo := repos.NewVideoRepo(dbx)
	shortVidRepo := repos.NewShortVideoRepo(dbx)
	cmtRepo := repos.NewCommentRepo(dbx)
	chatRepo := repos.NewChatRepo(dbx)
	liveRepo := repos.NewLiveRepo(dbx)
	baziRepo := repos.NewBaziRepo(dbx)

	// Public metadata: local memory/disk -> Redis -> MySQL. Private queries bypass it.
	var remote videocache.Remote
	if addr := os.Getenv("VIDEO_CACHE_REDIS_ADDR"); addr != "" {
		redisDB, _ := strconv.Atoi(os.Getenv("VIDEO_CACHE_REDIS_DB"))
		client := videocache.NewRedis(addr, os.Getenv("VIDEO_CACHE_REDIS_PASSWORD"), redisDB)
		defer client.Close()
		remote = client
		chatRepo.MessageCache = client
	}
	if os.Getenv("VIDEO_CACHE_ENABLED") != "false" {
		cacheDir := os.Getenv("VIDEO_CACHE_DIR")
		if cacheDir == "" {
			cacheDir = "/tmp/video-metadata-cache"
		}
		cache := videocache.New(cacheDir, cfg.DBHost+":"+cfg.DBName, remote)
		vidRepo.Cache, shortVidRepo.Cache, catRepo.Cache = cache, cache, cache
		ctx, cancel := context.WithCancel(context.Background())
		defer cancel()
		go cache.Run(ctx)
		go func() {
			// Warm the discovery entry points before the first visitor arrives.
			_, _ = shortVidRepo.ListCategories()
			_, _ = catRepo.List("long")
			_, _, _ = shortVidRepo.List(repos.ListShortVideoFilter{Limit: 30})
			_, _, _ = vidRepo.List(repos.ListVideoFilter{Type: "long", Limit: 20})
		}()
	}

	// WebSocket Hub
	hub := ws.NewHub()
	go hub.Run()

	// Handlers
	authH := &handlers.AuthHandler{Users: userRepo, Chat: chatRepo, JWTSecret: cfg.JWTSecret, JWTTTLHrs: cfg.JWTTTLHrs}
	catH := &handlers.CategoryHandler{Categories: catRepo}
	vidH := &handlers.VideoHandler{Videos: vidRepo}
	shortVidH := &handlers.ShortVideoHandler{Repo: shortVidRepo, EFVEndpoint: cfg.EFVEndpoint, EFVPlaybackKey: cfg.EFVPlaybackKey}
	cmtH := &handlers.CommentHandler{Comments: cmtRepo}
	chatH := &handlers.ChatHandler{Chat: chatRepo, Hub: hub, JWTSecret: cfg.JWTSecret}
	liveH := &handlers.LiveHandler{Repo: liveRepo}
	dreamH := &handlers.DreamHandler{AnthropicKey: cfg.AnthropicKey}
	baziH := &handlers.BaziHandler{AnthropicKey: cfg.AnthropicKey, Repo: baziRepo}
	ogH := &handlers.OGHandler{Repo: baziRepo, WebDistPath: cfg.WebDistPath}
	fishingH := &handlers.FishingHandler{}

	// Gin
	if cfg.AppEnv == "production" {
		gin.SetMode(gin.ReleaseMode)
	}
	r := gin.Default()
	r.Use(cors.New(cors.Config{
		AllowOrigins:     []string{cfg.CORS},
		AllowMethods:     []string{"GET", "POST", "PUT", "PATCH", "DELETE", "OPTIONS"},
		AllowHeaders:     []string{"Authorization", "Content-Type", "Accept"},
		AllowCredentials: false,
	}))

	// 健康检查
	r.GET("/up", func(c *gin.Context) { c.String(200, "ok") })

	// 八字命盘分享（公开）
	r.GET("/api/bazi/chart/:token", baziH.GetChart)
	// OG 分享卡 — /chart/:token 注入 OG meta tags 后返回 Vue SPA
	r.GET("/chart/:token", ogH.ServeChart)
	// OG 预览图
	r.GET("/api/og/chart/:token", ogH.ServeImage)

	api := r.Group("/api")

	// === 公开接口 ===
	api.POST("/register", authH.Register)
	api.POST("/login", authH.Login)
	api.GET("/categories", catH.List)
	api.GET("/videos", vidH.List)
	api.GET("/videos/:id", vidH.Show)
	api.GET("/videos/:id/comments", cmtH.List)
	api.GET("/chat/global", chatH.Global)

	// 短视频公开接口
	api.GET("/short-categories", shortVidH.ListCategories)
	api.GET("/short-videos", shortVidH.List)
	api.GET("/short-videos/:id", middleware.AuthOptional(cfg.JWTSecret), shortVidH.Show)
	api.GET("/short-videos/:id/interactions", middleware.AuthOptional(cfg.JWTSecret), shortVidH.Interactions)
	api.GET("/short-videos/:id/comments", shortVidH.ListComments)
	api.GET("/short-videos/:id/share", shortVidH.Share)

	// 直播公开接口
	api.GET("/live-categories", liveH.ListCategories)
	api.GET("/live-streams", liveH.List)

	// === 需要登录 ===
	authMW := middleware.AuthRequired(cfg.JWTSecret)
	priv := api.Group("")
	priv.Use(authMW)
	{
		priv.POST("/logout", authH.Logout)
		priv.GET("/me", authH.Me)
		priv.PATCH("/me", authH.UpdateMe)

		priv.POST("/videos/:id/like", vidH.Like)
		priv.GET("/me/videos", vidH.Mine)

		// 短视频互动
		priv.POST("/short-videos/:id/like", shortVidH.Like)
		priv.POST("/short-videos/:id/favorite", shortVidH.Favorite)
		priv.POST("/short-videos/:id/comments", shortVidH.CreateComment)
		priv.GET("/me/short-videos", shortVidH.Mine)

		priv.POST("/videos/:id/comments", cmtH.Create)
		priv.DELETE("/comments/:id", cmtH.Delete)

		priv.POST("/dreams/interpret", dreamH.Interpret)
		priv.POST("/bazi/calculate", baziH.Calculate)

		// 捕鱼游戏
		priv.POST("/fishing/fire", fishingH.Fire)
		priv.GET("/fishing/balance", fishingH.Balance)

		priv.POST("/chat/direct", chatH.Direct)
		priv.GET("/chat/rooms", chatH.MyRooms)
		priv.GET("/chat/rooms/:id/messages", chatH.Messages)
		priv.POST("/chat/rooms/:id/messages", chatH.Send)
		priv.POST("/chat/rooms/:id/join", chatH.Join)
		priv.POST("/chat/rooms/:id/read", chatH.MarkRead)
	}

	// WebSocket（query 里带 token）
	r.GET("/ws/chat", chatH.WSConnect)

	// 启动时打印所有注册的路由，方便验证 binary 是否包含最新代码
	for _, info := range r.Routes() {
		log.Printf("route  %-6s %s", info.Method, info.Path)
	}

	addr := ":" + cfg.AppPort
	log.Printf("video-api listening on %s (env=%s)", addr, cfg.AppEnv)
	if err := r.Run(addr); err != nil {
		log.Fatal(err)
	}
}
