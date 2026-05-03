package handlers

import (
	"database/sql"
	"net/http"
	"strconv"

	"github.com/gin-gonic/gin"
	"github.com/yourname/video-api/internal/middleware"
	"github.com/yourname/video-api/internal/models"
	"github.com/yourname/video-api/internal/repos"
)

type CommentHandler struct {
	Comments *repos.CommentRepo
}

func (h *CommentHandler) List(c *gin.Context) {
	videoID, _ := strconv.ParseInt(c.Param("id"), 10, 64)
	page := atoi(c.DefaultQuery("page", "1"))
	per := atoi(c.DefaultQuery("per_page", "20"))
	list, total, err := h.Comments.ListRoots(videoID, per, (page-1)*per)
	if err != nil {
		c.JSON(http.StatusInternalServerError, gin.H{"error": err.Error()})
		return
	}
	c.JSON(http.StatusOK, gin.H{"data": list, "total": total, "page": page, "per_page": per})
}

type createCommentReq struct {
	Content  string `json:"content"   binding:"required,max=2000"`
	ParentID *int64 `json:"parent_id"`
}

func (h *CommentHandler) Create(c *gin.Context) {
	videoID, _ := strconv.ParseInt(c.Param("id"), 10, 64)
	uid := middleware.CurrentUserID(c)
	var req createCommentReq
	if err := c.ShouldBindJSON(&req); err != nil {
		c.JSON(http.StatusUnprocessableEntity, gin.H{"error": err.Error()})
		return
	}
	cmt := &models.Comment{
		UserID:  uid,
		VideoID: videoID,
		Content: req.Content,
	}
	if req.ParentID != nil {
		cmt.ParentID = sql.NullInt64{Int64: *req.ParentID, Valid: true}
	}
	if err := h.Comments.Create(cmt); err != nil {
		c.JSON(http.StatusInternalServerError, gin.H{"error": err.Error()})
		return
	}
	c.JSON(http.StatusCreated, cmt)
}

func (h *CommentHandler) Delete(c *gin.Context) {
	id, _ := strconv.ParseInt(c.Param("id"), 10, 64)
	uid := middleware.CurrentUserID(c)
	role, _ := c.Get(middleware.CtxRole)

	cmt, err := h.Comments.FindByID(id)
	if err != nil || cmt == nil {
		c.JSON(http.StatusNotFound, gin.H{"error": "not found"})
		return
	}
	if cmt.UserID != uid && role != "admin" {
		c.JSON(http.StatusForbidden, gin.H{"error": "forbidden"})
		return
	}
	if err := h.Comments.Delete(cmt.ID, cmt.VideoID); err != nil {
		c.JSON(http.StatusInternalServerError, gin.H{"error": err.Error()})
		return
	}
	c.JSON(http.StatusOK, gin.H{"ok": true})
}
