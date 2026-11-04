package handlers

import (
	"net/url"
	"os"
	"path/filepath"
	"testing"

	"github.com/yourname/video-api/internal/models"
)

func TestEFVMediaOriginReload(t *testing.T) {
	file := filepath.Join(t.TempDir(), "domains.json")
	handler := &ShortVideoHandler{EFVEndpoint: "https://efv.example", EFVPlaybackKey: "secret"}
	const stored = "https://efv.example/videos/test/index.m3u8"
	for _, host := range []string{"first.example", "second.example"} {
		if err := os.WriteFile(file, []byte("{\"m3u8_origin\":\"https://"+host+"\"}"), 0600); err != nil {
			t.Fatal(err)
		}
		video := models.ShortVideoWithRelations{}
		video.URL = stored
		handler.applyMediaDomain(&video, readEFVM3U8Origin(file), true)
		got, err := url.Parse(video.URL)
		if err != nil || got.Host != host || got.Path != "/videos/test/index.m3u8" || got.Query().Get("key") == "" {
			t.Fatalf("expected signed URL on the current configured host %s", host)
		}
	}
	video := models.ShortVideoWithRelations{}
	video.URL = "https://unrelated.example/videos/test/index.m3u8"
	handler.applyMediaDomain(&video, readEFVM3U8Origin(file), true)
	if video.URL != "https://unrelated.example/videos/test/index.m3u8" {
		t.Fatal("unrelated media was rewritten")
	}
}

func TestEFVFrontendCoverRoute(t *testing.T) {
	handler := &ShortVideoHandler{EFVEndpoint: "https://efv.example"}
	video := models.ShortVideoWithRelations{}
	video.ID, video.Status, video.URL = 8, "published", "https://efv.example/videos/test/index.m3u8"
	handler.applyMediaDomain(&video, nil, false)
	if video.Cover == nil || *video.Cover != "/efv-covers/8" {
		t.Fatal("expected dynamic EFV cover URL")
	}
	custom := "shorts/covers/manual.jpg"
	video.Cover = &custom
	handler.applyMediaDomain(&video, nil, false)
	if *video.Cover != custom {
		t.Fatal("manually uploaded cover was replaced")
	}
}
