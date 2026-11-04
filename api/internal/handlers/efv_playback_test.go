package handlers

import (
	"net/url"
	"testing"
	"time"
)

func TestSignEFVPlayback(t *testing.T) {
	const endpoint = "https://efv.example"
	const mediaPath = "/videos/202201/31/61f68205eeeec878ae3fe3d5/217g7g/index.m3u8"
	now := time.UnixMilli(1613982300000)
	signed := signEFVPlayback(endpoint+mediaPath+"?quality=hd&key=stale", endpoint, "efvtoken", now)
	parsed, err := url.Parse(signed)
	if err != nil {
		t.Fatal(err)
	}
	query := parsed.Query()
	if parsed.Path != mediaPath || query.Get("key") != "bc82030c3c4aa8fa5d950ac9bbb32442" ||
		query.Get("counts") != "5" || query.Get("timestamp") != "1613982600000" || query.Get("quality") != "hd" {
		t.Fatal("incorrect EFV signature or query")
	}
	auto := endpoint + "/movie/auto/61f68205eeeec878ae3fe3d5.m3u8"
	autoURL, err := url.Parse(signEFVPlayback(auto, endpoint, "efvtoken", now))
	if err != nil || autoURL.Query().Get("key") != "fbf427ff92c9e08bdeeba3137b7c86e7" {
		t.Fatal("incorrect adaptive EFV signature")
	}
	for _, raw := range []string{
		"https://other.example" + mediaPath,
		"https://efv.example:8443" + mediaPath,
		endpoint + "/videos/sample.mp4",
		endpoint + "/api/other.m3u8",
	} {
		if signEFVPlayback(raw, endpoint, "efvtoken", now) != raw {
			t.Fatal("signed an unrelated URL")
		}
	}
	if signEFVPlayback(auto, endpoint, "", now) != auto {
		t.Fatal("changed URL without a configured key")
	}
}
