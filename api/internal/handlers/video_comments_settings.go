package handlers

import (
	"encoding/json"
	"github.com/gin-gonic/gin"
	"net/http"
	"os"
)

const videoCommentsSettingsFile = "/etc/video/efv-media/comments.json"

func videoCommentsEnabled(filename string) bool {
	data, err := os.ReadFile(filename)
	if os.IsNotExist(err) {
		return true
	}
	if err != nil {
		return false
	}
	var settings struct {
		Enabled *bool `json:"enabled"`
	}
	if json.Unmarshal(data, &settings) != nil || settings.Enabled == nil {
		return false
	}
	return *settings.Enabled
}

func VideoCommentsSettings(c *gin.Context) {
	c.Header("Cache-Control", "no-store")
	c.JSON(http.StatusOK, gin.H{"enabled": videoCommentsEnabled(videoCommentsSettingsFile)})
}
