package handlers

import (
	"net/http"

	"github.com/gin-gonic/gin"
	"github.com/yourname/video-api/internal/auth"
	"github.com/yourname/video-api/internal/middleware"
	"github.com/yourname/video-api/internal/models"
	"github.com/yourname/video-api/internal/repos"
)

type AuthHandler struct {
	Users     *repos.UserRepo
	Chat      *repos.ChatRepo
	JWTSecret string
	JWTTTLHrs int
}

type registerReq struct {
	Name     string `json:"name"     binding:"required,max=60"`
	Username string `json:"username" binding:"required,max=40"`
	Email    string `json:"email"    binding:"required,email"`
	Password string `json:"password" binding:"required,min=6"`
}

type loginReq struct {
	Email    string `json:"email"    binding:"required,email"`
	Password string `json:"password" binding:"required"`
}

type authResp struct {
	User  *models.User `json:"user"`
	Token string       `json:"token"`
}

func (h *AuthHandler) Register(c *gin.Context) {
	var req registerReq
	if err := c.ShouldBindJSON(&req); err != nil {
		c.JSON(http.StatusUnprocessableEntity, gin.H{"error": err.Error()})
		return
	}
	if exists, _ := h.Users.EmailExists(req.Email); exists {
		c.JSON(http.StatusUnprocessableEntity, gin.H{"error": "邮箱已存在"})
		return
	}
	if exists, _ := h.Users.UsernameExists(req.Username); exists {
		c.JSON(http.StatusUnprocessableEntity, gin.H{"error": "用户名已被占用"})
		return
	}
	hash, err := auth.HashPassword(req.Password)
	if err != nil {
		c.JSON(http.StatusInternalServerError, gin.H{"error": err.Error()})
		return
	}
	u := &models.User{
		Name:     req.Name,
		Username: req.Username,
		Email:    req.Email,
		Password: hash,
		Role:     "user",
		IsActive: true,
	}
	if err := h.Users.Create(u); err != nil {
		c.JSON(http.StatusInternalServerError, gin.H{"error": err.Error()})
		return
	}
	// 自动加入全局群
	if room, _ := h.Chat.GlobalRoom(); room != nil {
		_ = h.Chat.Join(room.ID, u.ID)
	}

	tok, err := auth.Issue(h.JWTSecret, h.JWTTTLHrs, u.ID, u.Role)
	if err != nil {
		c.JSON(http.StatusInternalServerError, gin.H{"error": err.Error()})
		return
	}
	c.JSON(http.StatusCreated, authResp{User: u, Token: tok})
}

func (h *AuthHandler) Login(c *gin.Context) {
	var req loginReq
	if err := c.ShouldBindJSON(&req); err != nil {
		c.JSON(http.StatusUnprocessableEntity, gin.H{"error": err.Error()})
		return
	}
	u, err := h.Users.FindByEmail(req.Email)
	if err != nil {
		c.JSON(http.StatusInternalServerError, gin.H{"error": err.Error()})
		return
	}
	if u == nil || !auth.CheckPassword(u.Password, req.Password) {
		c.JSON(http.StatusUnauthorized, gin.H{"error": "账号或密码错误"})
		return
	}
	if !u.IsActive {
		c.JSON(http.StatusForbidden, gin.H{"error": "账号已停用"})
		return
	}
	tok, err := auth.Issue(h.JWTSecret, h.JWTTTLHrs, u.ID, u.Role)
	if err != nil {
		c.JSON(http.StatusInternalServerError, gin.H{"error": err.Error()})
		return
	}
	c.JSON(http.StatusOK, authResp{User: u, Token: tok})
}

func (h *AuthHandler) Me(c *gin.Context) {
	uid := middleware.CurrentUserID(c)
	u, err := h.Users.FindByID(uid)
	if err != nil || u == nil {
		c.JSON(http.StatusNotFound, gin.H{"error": "user not found"})
		return
	}
	c.JSON(http.StatusOK, u)
}

type updateMeReq struct {
	Name   *string `json:"name"`
	Bio    *string `json:"bio"`
	Avatar *string `json:"avatar"`
	Phone  *string `json:"phone"`
}

func (h *AuthHandler) UpdateMe(c *gin.Context) {
	uid := middleware.CurrentUserID(c)
	var req updateMeReq
	if err := c.ShouldBindJSON(&req); err != nil {
		c.JSON(http.StatusUnprocessableEntity, gin.H{"error": err.Error()})
		return
	}
	if err := h.Users.UpdateProfile(uid, req.Name, req.Bio, req.Avatar, req.Phone); err != nil {
		c.JSON(http.StatusInternalServerError, gin.H{"error": err.Error()})
		return
	}
	u, _ := h.Users.FindByID(uid)
	c.JSON(http.StatusOK, u)
}

// Logout 无状态 JWT 这里只是个礼貌端点；客户端丢 token 即可
func (h *AuthHandler) Logout(c *gin.Context) {
	c.JSON(http.StatusOK, gin.H{"message": "logged out"})
}
