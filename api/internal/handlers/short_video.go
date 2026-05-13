package handlers

import (
	"net/http"
	"strconv"

	"github.com/gin-gonic/gin"
	"github.com/yourname/video-api/internal/middleware"
	"github.com/yourname/video-api/internal/models"
	"github.com/yourname/video-api/internal/repos"
)

type ShortVideoHandler struct {
	Repo *repos.ShortVideoRepo
}

func (h *ShortVideoHandler) List(c *gin.Context) {
	f := repos.ListShortVideoFilter{
		Keyword: c.Query("q"),
		Limit:   atoi(c.DefaultQuery("per_page", "20")),
		Offset:  (atoi(c.DefaultQuery("page", "1")) - 1) * atoi(c.DefaultQuery("per_page", "20")),
	}
	if cid, err := strconv.ParseInt(c.Query("category_id"), 10, 64); err == nil {
		f.CategoryID = cid
	}
	list, total, err := h.Repo.List(f)
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

func (h *ShortVideoHandler) Show(c *gin.Context) {
	id, _ := strconv.ParseInt(c.Param("id"), 10, 64)
	v, err := h.Repo.FindByID(id)
	if err != nil {
		c.JSON(http.StatusInternalServerError, gin.H{"error": err.Error()})
		return
	}
	if v == nil {
		c.JSON(http.StatusNotFound, gin.H{"error": "not found"})
		return
	}
	_ = h.Repo.IncrementViews(id)

	liked := false
	favorited := false
	uid := middleware.CurrentUserID(c)
	if uid > 0 {
		liked, _ = h.Repo.IsLiked(uid, id)
		favorited, _ = h.Repo.IsFavorited(uid, id)
	}

	c.JSON(http.StatusOK, gin.H{
		"video":     v,
		"liked":     liked,
		"favorited": favorited,
	})
}

func (h *ShortVideoHandler) Like(c *gin.Context) {
	uid := middleware.CurrentUserID(c)
	if uid == 0 {
		c.JSON(http.StatusUnauthorized, gin.H{"error": "unauthorized"})
		return
	}
	id, _ := strconv.ParseInt(c.Param("id"), 10, 64)
	liked, count, err := h.Repo.ToggleLike(uid, id)
	if err != nil {
		c.JSON(http.StatusInternalServerError, gin.H{"error": err.Error()})
		return
	}
	c.JSON(http.StatusOK, gin.H{"liked": liked, "count": count})
}

func (h *ShortVideoHandler) Favorite(c *gin.Context) {
	uid := middleware.CurrentUserID(c)
	if uid == 0 {
		c.JSON(http.StatusUnauthorized, gin.H{"error": "unauthorized"})
		return
	}
	id, _ := strconv.ParseInt(c.Param("id"), 10, 64)
	favorited, count, err := h.Repo.ToggleFavorite(uid, id)
	if err != nil {
		c.JSON(http.StatusInternalServerError, gin.H{"error": err.Error()})
		return
	}
	c.JSON(http.StatusOK, gin.H{"favorited": favorited, "count": count})
}

func (h *ShortVideoHandler) Share(c *gin.Context) {
	id, _ := strconv.ParseInt(c.Param("id"), 10, 64)
	v, err := h.Repo.FindByID(id)
	if err != nil || v == nil {
		c.JSON(http.StatusNotFound, gin.H{"error": "not found"})
		return
	}
	_ = h.Repo.IncrementShares(id)
	shareURL := c.Query("base_url") + "/short-video/" + strconv.FormatInt(id, 10)
	c.JSON(http.StatusOK, gin.H{"share_url": shareURL})
}

func (h *ShortVideoHandler) ListComments(c *gin.Context) {
	vid, _ := strconv.ParseInt(c.Param("id"), 10, 64)
	limit := atoi(c.DefaultQuery("per_page", "20"))
	offset := (atoi(c.DefaultQuery("page", "1")) - 1) * limit
	comments, err := h.Repo.ListComments(vid, limit, offset)
	if err != nil {
		c.JSON(http.StatusInternalServerError, gin.H{"error": err.Error()})
		return
	}
	c.JSON(http.StatusOK, gin.H{"data": comments})
}

func (h *ShortVideoHandler) CreateComment(c *gin.Context) {
	uid := middleware.CurrentUserID(c)
	if uid == 0 {
		c.JSON(http.StatusUnauthorized, gin.H{"error": "unauthorized"})
		return
	}
	vid, _ := strconv.ParseInt(c.Param("id"), 10, 64)
	var body struct {
		Content  string `json:"content"`
		ParentID *int64 `json:"parent_id"`
	}
	if err := c.ShouldBindJSON(&body); err != nil {
		c.JSON(http.StatusBadRequest, gin.H{"error": "invalid request"})
		return
	}
	comment := &models.ShortVideoComment{
		UserID:  uid,
		VideoID: vid,
		Content: body.Content,
	}
	if body.ParentID != nil {
		comment.ParentID.Valid = true
		comment.ParentID.Int64 = *body.ParentID
	}
	if err := h.Repo.CreateComment(comment); err != nil {
		c.JSON(http.StatusInternalServerError, gin.H{"error": err.Error()})
		return
	}
	c.JSON(http.StatusCreated, gin.H{"data": comment})
}

func (h *ShortVideoHandler) ListCategories(c *gin.Context) {
	cats, err := h.Repo.ListCategories()
	if err != nil {
		c.JSON(http.StatusInternalServerError, gin.H{"error": err.Error()})
		return
	}
	c.JSON(http.StatusOK, gin.H{"data": cats})
}

func (h *ShortVideoHandler) Mine(c *gin.Context) {
	uid := middleware.CurrentUserID(c)
	page := atoi(c.DefaultQuery("page", "1"))
	per := atoi(c.DefaultQuery("per_page", "20"))
	list, total, err := h.Repo.List(repos.ListShortVideoFilter{
		UserID:     uid,
		Limit:      per,
		Offset:     (page - 1) * per,
		IncludeAll: true,
	})
	if err != nil {
		c.JSON(http.StatusInternalServerError, gin.H{"error": err.Error()})
		return
	}
	c.JSON(http.StatusOK, gin.H{"data": list, "total": total, "page": page, "per_page": per})
}
