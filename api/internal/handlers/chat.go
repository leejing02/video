package handlers

import (
	"database/sql"
	"net/http"
	"strconv"

	"github.com/gin-gonic/gin"
	"github.com/yourname/video-api/internal/auth"
	"github.com/yourname/video-api/internal/middleware"
	"github.com/yourname/video-api/internal/models"
	"github.com/yourname/video-api/internal/repos"
	"github.com/yourname/video-api/internal/ws"
)

type ChatHandler struct {
	Chat      *repos.ChatRepo
	Hub       *ws.Hub
	JWTSecret string
}

// GET /chat/global
func (h *ChatHandler) Global(c *gin.Context) {
	r, err := h.Chat.GlobalRoom()
	if err != nil {
		c.JSON(http.StatusInternalServerError, gin.H{"error": err.Error()})
		return
	}
	if r == nil {
		c.JSON(http.StatusNotFound, gin.H{"error": "global room not configured"})
		return
	}
	c.JSON(http.StatusOK, r)
}

// GET /chat/rooms
func (h *ChatHandler) MyRooms(c *gin.Context) {
	uid := middleware.CurrentUserID(c)
	rooms, err := h.Chat.UserRooms(uid)
	if err != nil {
		c.JSON(http.StatusInternalServerError, gin.H{"error": err.Error()})
		return
	}
	c.JSON(http.StatusOK, rooms)
}

// GET /chat/rooms/:id/messages?before_id=&limit=
func (h *ChatHandler) Messages(c *gin.Context) {
	roomID, _ := strconv.ParseInt(c.Param("id"), 10, 64)
	beforeID, _ := strconv.ParseInt(c.Query("before_id"), 10, 64)
	limit, _ := strconv.Atoi(c.DefaultQuery("limit", "30"))

	if err := h.ensureCanRead(c, roomID); err != nil {
		return // 错误已写
	}
	msgs, err := h.Chat.Messages(roomID, beforeID, limit)
	if err != nil {
		c.JSON(http.StatusInternalServerError, gin.H{"error": err.Error()})
		return
	}
	c.JSON(http.StatusOK, msgs)
}

type sendMsgReq struct {
	Type          string `json:"type"`
	Content       string `json:"content" binding:"required,max=2000"`
	ReplyToID     *int64 `json:"reply_to_id"`
	AttachmentURL string `json:"attachment_url"`
}

// POST /chat/rooms/:id/messages
func (h *ChatHandler) Send(c *gin.Context) {
	roomID, _ := strconv.ParseInt(c.Param("id"), 10, 64)
	uid := middleware.CurrentUserID(c)
	if err := h.ensureCanRead(c, roomID); err != nil {
		return
	}
	var req sendMsgReq
	if err := c.ShouldBindJSON(&req); err != nil {
		c.JSON(http.StatusUnprocessableEntity, gin.H{"error": err.Error()})
		return
	}
	if req.Type == "" {
		req.Type = "text"
	}
	m := &models.ChatMessage{
		ChatRoomID: roomID,
		UserID:     uid,
		Type:       req.Type,
		Content:    req.Content,
	}
	if req.ReplyToID != nil {
		m.ReplyToID = sql.NullInt64{Int64: *req.ReplyToID, Valid: true}
	}
	if req.AttachmentURL != "" {
		m.AttachmentURL = &req.AttachmentURL
	}
	if err := h.Chat.CreateMessage(m); err != nil {
		c.JSON(http.StatusInternalServerError, gin.H{"error": err.Error()})
		return
	}

	// 拼装带 user 的消息广播
	full, err := h.Chat.LoadMessageWithUser(m.ID)
	if err == nil && full != nil {
		h.Hub.Broadcast(roomID, "message.sent", full)
	}
	c.JSON(http.StatusCreated, full)
}

// POST /chat/rooms/:id/join
func (h *ChatHandler) Join(c *gin.Context) {
	roomID, _ := strconv.ParseInt(c.Param("id"), 10, 64)
	uid := middleware.CurrentUserID(c)
	r, err := h.Chat.RoomByID(roomID)
	if err != nil || r == nil {
		c.JSON(http.StatusNotFound, gin.H{"error": "room not found"})
		return
	}
	if r.Kind == "global" {
		c.JSON(http.StatusOK, gin.H{"ok": true, "global": true})
		return
	}
	if err := h.Chat.Join(roomID, uid); err != nil {
		c.JSON(http.StatusInternalServerError, gin.H{"error": err.Error()})
		return
	}
	c.JSON(http.StatusOK, gin.H{"ok": true})
}

// POST /chat/rooms/:id/read
func (h *ChatHandler) MarkRead(c *gin.Context) {
	roomID, _ := strconv.ParseInt(c.Param("id"), 10, 64)
	uid := middleware.CurrentUserID(c)
	if err := h.Chat.MarkRead(roomID, uid); err != nil {
		c.JSON(http.StatusInternalServerError, gin.H{"error": err.Error()})
		return
	}
	c.JSON(http.StatusOK, gin.H{"ok": true})
}

// GET /ws/chat?room_id=&token=...
//
// 浏览器 / iOS 端连这个把自己挂到 hub 上接收消息广播。
// 这里独立校验 token（query 参数），因为 WebSocket 不方便带 Header。
func (h *ChatHandler) WSConnect(c *gin.Context) {
	roomID, _ := strconv.ParseInt(c.Query("room_id"), 10, 64)
	token := c.Query("token")
	if roomID == 0 || token == "" {
		c.JSON(http.StatusBadRequest, gin.H{"error": "room_id 和 token 必填"})
		return
	}
	claims, err := auth.Parse(h.JWTSecret, token)
	if err != nil {
		c.JSON(http.StatusUnauthorized, gin.H{"error": "无效 token"})
		return
	}
	// 房间存在 + 用户能进
	r, err := h.Chat.RoomByID(roomID)
	if err != nil || r == nil {
		c.JSON(http.StatusNotFound, gin.H{"error": "room not found"})
		return
	}
	if r.Kind != "global" {
		isMember, _ := h.Chat.IsMember(roomID, claims.UserID)
		if !isMember {
			c.JSON(http.StatusForbidden, gin.H{"error": "not a member"})
			return
		}
	}
	if err := ws.Serve(h.Hub, c.Writer, c.Request, roomID, claims.UserID); err != nil {
		// 已劫持，无法再写 JSON
		return
	}
}

// ensureCanRead 写错误并返回非 nil 表示已经处理
func (h *ChatHandler) ensureCanRead(c *gin.Context, roomID int64) error {
	r, err := h.Chat.RoomByID(roomID)
	if err != nil {
		c.JSON(http.StatusInternalServerError, gin.H{"error": err.Error()})
		return err
	}
	if r == nil {
		c.JSON(http.StatusNotFound, gin.H{"error": "room not found"})
		return ErrNotFound
	}
	if r.Kind == "global" {
		return nil
	}
	uid := middleware.CurrentUserID(c)
	isMember, err := h.Chat.IsMember(roomID, uid)
	if err != nil {
		c.JSON(http.StatusInternalServerError, gin.H{"error": err.Error()})
		return err
	}
	if !isMember {
		c.JSON(http.StatusForbidden, gin.H{"error": "not a member"})
		return ErrForbidden
	}
	return nil
}

// 简易哨兵错误
var (
	ErrNotFound  = stringError("not found")
	ErrForbidden = stringError("forbidden")
)

type stringError string

func (e stringError) Error() string { return string(e) }
