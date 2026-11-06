package handlers

import (
	"encoding/json"
	"fmt"
	"net/url"
	"os"
	"path"
	"regexp"
	"strings"
	"time"

	"github.com/gin-gonic/gin"

	"github.com/yourname/video-api/internal/models"
)

const efvMediaFile = "/etc/video/efv-media/domains.json"

func readEFVDomain(filename, key string) *url.URL {
	var domains map[string]string
	data, err := os.ReadFile(filename)
	if err != nil || json.Unmarshal(data, &domains) != nil {
		return nil
	}
	return efvMediaOrigin(domains[key])
}

func readEFVM3U8Origin(filename string) *url.URL { return readEFVDomain(filename, "m3u8_origin") }

func readEFVImageOrigin(filename string) *url.URL { return readEFVDomain(filename, "image_origin") }

var (
	// cover_source is an EFV media path the admin recorded when syncing 封面1,
	// e.g. /videos/202611/05/<id>/cover.webp. Only plain relative image paths.
	efvCoverSource = regexp.MustCompile(`^/videos/[A-Za-z0-9_/-]+\.(?:webp|jpe?g|png|gif)$`)
	// Covers the admin copied from EFV are named after their content hash.
	efvSyncedCover = regexp.MustCompile(`^shorts/covers/efv-([a-f0-9]{64})\.(?:jpg|png|webp|gif)$`)
)

// applyEFVImageCover serves the 封面1 address stored in the database from the
// configured EFV image domain (the browser decrypts it). The local copy stays as
// fallback; its content hash versions the URL, so a re-synced cover is not served
// from a year-long browser cache. Videos without a recorded source keep the copy.
func applyEFVImageCover(video *models.ShortVideoWithRelations, image *url.URL) {
	source := video.CoverSource
	video.CoverSource = nil
	if image == nil || video.Status != "published" || video.Cover == nil || source == nil ||
		!efvCoverSource.MatchString(*source) || strings.Contains(*source, "//") {
		return
	}
	cover := efvSyncedCover.FindStringSubmatch(*video.Cover)
	if cover == nil {
		return // A manually uploaded cover always wins.
	}
	local := *video.Cover
	remote := image.Scheme + "://" + image.Host + *source + "?v=" + cover[1][:12]
	video.CoverFallback = &local
	video.Cover = &remote
}

var efvAESKeyLength = map[string]int{"aes-128-ctr": 32, "aes-128-cbc": 32, "aes-256-ctr": 64, "aes-256-cbc": 64}

func isHex(value string) bool {
	for _, r := range value {
		if !strings.ContainsRune("0123456789abcdefABCDEF", r) {
			return false
		}
	}
	return true
}

// EFVImageConfig is what browsers need to fetch and decrypt EFV covers (per EFV's
// front-end decryption guide, the key is necessarily visible to clients).
type EFVImageConfig struct {
	MediaFile string
	Cipher    string
	Key       string
	IV        string
}

func (h *EFVImageConfig) Show(c *gin.Context) {
	c.Header("Cache-Control", "no-store")
	body := gin.H{"image_origin": nil, "cipher": nil, "key": nil, "iv": nil}
	if origin := readEFVImageOrigin(h.MediaFile); origin != nil {
		body["image_origin"] = origin.Scheme + "://" + origin.Host
	}
	cipher := strings.ToLower(h.Cipher)
	if n, ok := efvAESKeyLength[cipher]; ok && len(h.Key) == n && len(h.IV) == 32 && isHex(h.Key+h.IV) {
		body["cipher"], body["key"], body["iv"] = cipher, strings.ToLower(h.Key), strings.ToLower(h.IV)
	}
	c.JSON(200, body)
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
		isEFVRemoteCover(*video.Cover, origin)) {
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
