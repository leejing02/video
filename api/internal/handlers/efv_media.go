package handlers

import (
	"encoding/json"
	"fmt"
	"net/url"
	"os"
	"path"
	"strings"
	"time"

	"github.com/yourname/video-api/internal/models"
)

const efvMediaFile = "/etc/video/efv-media/domains.json"

func readEFVM3U8Origin(filename string) *url.URL {
	var domains map[string]string
	data, err := os.ReadFile(filename)
	if err != nil || json.Unmarshal(data, &domains) != nil {
		return nil
	}
	return efvMediaOrigin(domains["m3u8_origin"])
}

func efvMediaOrigin(raw string) *url.URL {
	u, err := url.Parse(raw)
	if err != nil || u.Scheme != "https" || u.Host == "" || u.User != nil ||
		(u.Path != "" && u.Path != "/") || u.RawQuery != "" || u.Fragment != "" {
		return nil
	}
	return u
}

func (h *ShortVideoHandler) applyMediaDomain(video *models.ShortVideoWithRelations, target *url.URL, sign bool) {
	origin := efvMediaOrigin(h.EFVEndpoint)
	media, err := url.Parse(video.URL)
	if err != nil || media.Scheme != "https" || media.User != nil || path.Clean(media.Path) != media.Path ||
		!(efvAutoPlaylist.MatchString(media.Path) || (strings.HasPrefix(media.Path, "/videos/") && strings.HasSuffix(media.Path, ".m3u8"))) {
		return
	}
	if !(origin != nil && strings.EqualFold(media.Host, origin.Host)) &&
		!(target != nil && strings.EqualFold(media.Host, target.Host)) {
		return
	}
	if video.Status == "published" && video.ID > 0 && (video.Cover == nil || *video.Cover == "" ||
		strings.HasPrefix(*video.Cover, "shorts/covers/efv-") || isEFVRemoteCover(*video.Cover, origin)) {
		cover := fmt.Sprintf("/efv-covers/%d", video.ID)
		video.Cover = &cover
	}
	if target != nil {
		media.Host = target.Host
	}
	video.URL = media.String()
	if sign {
		video.URL = signEFVPlayback(video.URL, "https://"+media.Host, h.EFVPlaybackKey, time.Now())
	}
}

func isEFVRemoteCover(raw string, origin *url.URL) bool {
	cover, err := url.Parse(raw)
	return origin != nil && err == nil && cover.Scheme == "https" && cover.User == nil && strings.EqualFold(cover.Host, origin.Host)
}
