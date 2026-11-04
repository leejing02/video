package handlers

import (
	"crypto/md5" // EFV requires MD5 for its timestamp signature.
	"fmt"
	"net/url"
	"path"
	"regexp"
	"strconv"
	"strings"
	"time"
)

var efvAutoPlaylist = regexp.MustCompile("^/movie/auto/[a-fA-F0-9]{24}\\.m3u8$")

func signEFVPlayback(raw, endpoint, secret string, now time.Time) string {
	if secret == "" {
		return raw
	}
	media, err := url.Parse(raw)
	if err != nil {
		return raw
	}
	origin, err := url.Parse(endpoint)
	if err != nil || media.Host == "" || !strings.EqualFold(media.Host, origin.Host) ||
		media.Scheme != "https" || origin.Scheme != "https" || media.User != nil ||
		path.Clean(media.Path) != media.Path {
		return raw
	}
	resource := media.EscapedPath()
	switch {
	case efvAutoPlaylist.MatchString(resource):
		resource = strings.TrimSuffix(strings.TrimPrefix(resource, "/movie"), ".m3u8")
	case strings.HasPrefix(resource, "/videos/") && strings.HasSuffix(resource, ".m3u8"):
	default:
		return raw
	}

	const counts = "5"
	expiry := strconv.FormatInt(now.Add(5*time.Minute).UnixMilli(), 10)
	payload := resource + "&counts=" + counts + "&timestamp=" + expiry + secret
	query := media.Query()
	query.Set("counts", counts)
	query.Set("timestamp", expiry)
	query.Set("key", fmt.Sprintf("%x", md5.Sum([]byte(payload))))
	media.RawQuery = query.Encode()
	return media.String()
}
