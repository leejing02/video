package handlers

import (
	"net/http"

	"github.com/gin-gonic/gin"
	"github.com/yourname/video-api/internal/repos"
)

type CategoryHandler struct {
	Categories *repos.CategoryRepo
}

func (h *CategoryHandler) List(c *gin.Context) {
	kind := c.Query("kind") // long / short / 空
	cats, err := h.Categories.List(kind)
	if err != nil {
		c.JSON(http.StatusInternalServerError, gin.H{"error": err.Error()})
		return
	}
	c.JSON(http.StatusOK, cats)
}
