// video-api server entrypoint
//
// 启动顺序：load .env → 连库 → 起 ws Hub → 注册 gin 路由 → ListenAndServe
package main

import (
	"log"

	"github.com/gin-contrib/cors"
	"github.com/gin-gonic/gin"

	"github.com/yourname/video-api/internal/config"
	"github.com/yourname/video-api/internal/db"
	"github.com/yourname/video-api/internal/handlers"
	"github.com/yourname/video-api/internal/middleware"
	"github.com/yourname/video-api/internal/repos"
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
	cmtRepo := repos.NewCommentRepo(dbx)
	chatRepo := repos.NewChatRepo(dbx)

	// WebSocket Hub
	hub := ws.NewHub()
	go hub.Run()

	// Handlers
	authH := &handlers.AuthHandler{Users: userRepo, Chat: chatRepo, JWTSecret: cfg.JWTSecret, JWTTTLHrs: cfg.JWTTTLHrs}
	catH := &handlers.CategoryHandler{Categories: catRepo}
	vidH := &handlers.VideoHandler{Videos: vidRepo}
	cmtH := &handlers.CommentHandler{Comments: cmtRepo}
	chatH := &handlers.ChatHandler{Chat: chatRepo, Hub: hub, JWTSecret: cfg.JWTSecret}

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

	api := r.Group("/api")

	// === 公开接口 ===
	api.POST("/register", authH.Register)
	api.POST("/login", authH.Login)
	api.GET("/categories", catH.List)
	api.GET("/videos", vidH.List)
	api.GET("/videos/:id", vidH.Show)
	api.GET("/videos/:id/comments", cmtH.List)
	api.GET("/chat/global", chatH.Global)

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

		priv.POST("/videos/:id/comments", cmtH.Create)
		priv.DELETE("/comments/:id", cmtH.Delete)

		priv.GET("/chat/rooms", chatH.MyRooms)
		priv.GET("/chat/rooms/:id/messages", chatH.Messages)
		priv.POST("/chat/rooms/:id/messages", chatH.Send)
		priv.POST("/chat/rooms/:id/join", chatH.Join)
		priv.POST("/chat/rooms/:id/read", chatH.MarkRead)
	}

	// WebSocket（query 里带 token）
	r.GET("/ws/chat", chatH.WSConnect)

	addr := ":" + cfg.AppPort
	log.Printf("video-api listening on %s (env=%s)", addr, cfg.AppEnv)
	if err := r.Run(addr); err != nil {
		log.Fatal(err)
	}
}
