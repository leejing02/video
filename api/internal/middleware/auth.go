package middleware

import (
	"net/http"
	"strings"

	"github.com/gin-gonic/gin"
	"github.com/yourname/video-api/internal/auth"
)

const (
	CtxUserID = "uid"
	CtxRole   = "role"
)

// AuthRequired 强制登录
func AuthRequired(secret string) gin.HandlerFunc {
	return func(c *gin.Context) {
		claims, ok := extract(c, secret)
		if !ok {
			c.AbortWithStatusJSON(http.StatusUnauthorized, gin.H{"error": "未登录或 token 无效"})
			return
		}
		c.Set(CtxUserID, claims.UserID)
		c.Set(CtxRole, claims.Role)
		c.Next()
	}
}

// AuthOptional 可选登录（公开接口里也允许带 token）
func AuthOptional(secret string) gin.HandlerFunc {
	return func(c *gin.Context) {
		if claims, ok := extract(c, secret); ok {
			c.Set(CtxUserID, claims.UserID)
			c.Set(CtxRole, claims.Role)
		}
		c.Next()
	}
}

// AdminOnly 必须为 admin
func AdminOnly() gin.HandlerFunc {
	return func(c *gin.Context) {
		role, _ := c.Get(CtxRole)
		if role != "admin" {
			c.AbortWithStatusJSON(http.StatusForbidden, gin.H{"error": "需要管理员权限"})
			return
		}
		c.Next()
	}
}

func extract(c *gin.Context, secret string) (*auth.Claims, bool) {
	h := c.GetHeader("Authorization")
	if !strings.HasPrefix(h, "Bearer ") {
		return nil, false
	}
	raw := strings.TrimPrefix(h, "Bearer ")
	claims, err := auth.Parse(secret, raw)
	if err != nil {
		return nil, false
	}
	return claims, true
}

// CurrentUserID 取当前用户 id（未登录返回 0）
func CurrentUserID(c *gin.Context) int64 {
	if v, ok := c.Get(CtxUserID); ok {
		if id, ok := v.(int64); ok {
			return id
		}
	}
	return 0
}
