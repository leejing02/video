package handlers

import (
	"net/http"
	"strconv"

	"github.com/gin-gonic/gin"
	"github.com/yourname/video-api/internal/repos"
)

type LiveHandler struct {
	Repo *repos.LiveRepo
}

func (h *LiveHandler) ListCategories(c *gin.Context) {
	cats, err := h.Repo.Categories()
	if err != nil {
		c.JSON(http.StatusInternalServerError, gin.H{"error": err.Error()})
		return
	}
	c.JSON(http.StatusOK, gin.H{"data": cats})
}

func (h *LiveHandler) List(c *gin.Context) {
	catID, _ := strconv.ParseInt(c.Query("category_id"), 10, 64)
	page, _ := strconv.Atoi(c.DefaultQuery("page", "1"))
	perPage, _ := strconv.Atoi(c.DefaultQuery("per_page", "20"))

	streams, total, err := h.Repo.List(catID, page, perPage)
	if err != nil {
		c.JSON(http.StatusInternalServerError, gin.H{"error": err.Error()})
		return
	}
	c.JSON(http.StatusOK, gin.H{
		"data": streams,
		"meta": gin.H{
			"total":       total,
			"page":        page,
			"per_page":    perPage,
			"category_id": catID,
		},
	})
}
