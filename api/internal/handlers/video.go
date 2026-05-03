package handlers

import (
	"net/http"
	"strconv"

	"github.com/gin-gonic/gin"
	"github.com/yourname/video-api/internal/middleware"
	"github.com/yourname/video-api/internal/repos"
)

type VideoHandler struct {
	Videos *repos.VideoRepo
}

func (h *VideoHandler) List(c *gin.Context) {
	f := repos.ListVideoFilter{
		Type:    c.Query("type"),
		Keyword: c.Query("q"),
		Limit:   atoi(c.DefaultQuery("per_page", "20")),
		Offset:  (atoi(c.DefaultQuery("page", "1")) - 1) * atoi(c.DefaultQuery("per_page", "20")),
	}
	if cid, err := strconv.ParseInt(c.Query("category_id"), 10, 64); err == nil {
		f.CategoryID = cid
	}
	list, total, err := h.Videos.List(f)
	if err != nil {
		c.JSON(http.StatusInternalServerError, gin.H{"error": err.Error()})
		return
	}
	c.JSON(http.StatusOK, gin.H{
		"data":     list,
		"total":    total,
		"per_page": f.Limit,
		"page":     atoi(c.DefaultQuery("page", "1")),
	})
}

func (h *VideoHandler) Show(c *gin.Context) {
	id, _ := strconv.ParseInt(c.Param("id"), 10, 64)
	v, err := h.Videos.FindByID(id)
	if err != nil {
		c.JSON(http.StatusInternalServerError, gin.H{"error": err.Error()})
		return
	}
	if v == nil {
		c.JSON(http.StatusNotFound, gin.H{"error": "not found"})
		return
	}
	_ = h.Videos.IncrementViews(id)
	c.JSON(http.StatusOK, v)
}

func (h *VideoHandler) Like(c *gin.Context) {
	uid := middleware.CurrentUserID(c)
	id, _ := strconv.ParseInt(c.Param("id"), 10, 64)
	liked, likes, err := h.Videos.ToggleLike(uid, id)
	if err != nil {
		c.JSON(http.StatusInternalServerError, gin.H{"error": err.Error()})
		return
	}
	c.JSON(http.StatusOK, gin.H{"liked": liked, "likes": likes})
}

func (h *VideoHandler) Mine(c *gin.Context) {
	uid := middleware.CurrentUserID(c)
	page := atoi(c.DefaultQuery("page", "1"))
	per := atoi(c.DefaultQuery("per_page", "20"))
	list, total, err := h.Videos.List(repos.ListVideoFilter{
		UserID: uid, Limit: per, Offset: (page - 1) * per,
	})
	if err != nil {
		c.JSON(http.StatusInternalServerError, gin.H{"error": err.Error()})
		return
	}
	c.JSON(http.StatusOK, gin.H{"data": list, "total": total, "page": page, "per_page": per})
}

func atoi(s string) int {
	n, _ := strconv.Atoi(s)
	if n <= 0 {
		return 1
	}
	return n
}
