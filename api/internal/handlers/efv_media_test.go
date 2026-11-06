package handlers

import (
	"encoding/json"
	"net/http/httptest"
	"net/url"
	"os"
	"path/filepath"
	"strings"
	"testing"

	"github.com/gin-gonic/gin"

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
	for _, storedCover := range []string{"shorts/covers/efv-old.webp", "shorts/covers/efv-new.webp"} {
		video.Cover = &storedCover
		handler.applyMediaDomain(&video, nil, false)
		if *video.Cover != storedCover {
			t.Fatal("synced cover must keep its content-versioned storage path")
		}
	}
	custom := "shorts/covers/manual.jpg"
	video.Cover = &custom
	handler.applyMediaDomain(&video, nil, false)
	if *video.Cover != custom {
		t.Fatal("manually uploaded cover was replaced")
	}
}

func TestEFVImageCover(t *testing.T) {
	image, _ := url.Parse("https://tupian.example")
	hash := "56e89aae0f407eb70ee96b476bb182a26623a853420d7e5df58f555c71b751e5"
	synced := "shorts/covers/efv-" + hash + ".webp"
	video := models.ShortVideoWithRelations{}
	video.Status, video.Cover = "published", &synced
	video.URL = "https://m3u8.example/videos/202611/05/6aec7c9ff8bd243144f7b719/c7e47c/index.m3u8?key=x"
	applyEFVImageCover(&video, image)
	want := "https://tupian.example/videos/202611/05/6aec7c9ff8bd243144f7b719/cover.webp?v=56e89aae0f40"
	if video.Cover == nil || *video.Cover != want || video.CoverFallback == nil || *video.CoverFallback != synced {
		t.Fatalf("expected EFV image URL with local fallback, got %v", video.Cover)
	}

	for name, mutate := range map[string]func(*models.ShortVideoWithRelations){
		"manual cover":  func(v *models.ShortVideoWithRelations) { c := "shorts/covers/manual.jpg"; v.Cover = &c },
		"draft":         func(v *models.ShortVideoWithRelations) { v.Status = "draft" },
		"non-EFV video": func(v *models.ShortVideoWithRelations) { v.URL = "https://cdn.example/a/index.m3u8" },
		"auto playlist": func(v *models.ShortVideoWithRelations) {
			v.URL = "https://efv.example/movie/auto/6aec7c9ff8bd243144f7b719.m3u8"
		},
	} {
		v := models.ShortVideoWithRelations{}
		c := synced
		v.Status, v.Cover, v.URL = "published", &c, video.URL
		mutate(&v)
		before := *v.Cover
		applyEFVImageCover(&v, image)
		if *v.Cover != before || v.CoverFallback != nil {
			t.Fatalf("%s: cover must stay local", name)
		}
	}
	v := models.ShortVideoWithRelations{}
	c := synced
	v.Status, v.Cover, v.URL = "published", &c, video.URL
	applyEFVImageCover(&v, nil)
	if *v.Cover != synced {
		t.Fatal("without an image domain the local cover must be kept")
	}
}

func TestEFVImageConfig(t *testing.T) {
	gin.SetMode(gin.TestMode)
	file := filepath.Join(t.TempDir(), "domains.json")
	_ = os.WriteFile(file, []byte(`{"image_origin":"https://tupian.example"}`), 0600)
	run := func(h *EFVImageConfig) map[string]any {
		w := httptest.NewRecorder()
		c, _ := gin.CreateTestContext(w)
		h.Show(c)
		var body map[string]any
		_ = json.Unmarshal(w.Body.Bytes(), &body)
		return body
	}
	key := strings.Repeat("ab", 32)
	body := run(&EFVImageConfig{MediaFile: file, Cipher: "AES-256-CTR", Key: key, IV: strings.Repeat("0", 32)})
	if body["image_origin"] != "https://tupian.example" || body["cipher"] != "aes-256-ctr" || body["key"] != key {
		t.Fatalf("unexpected config %v", body)
	}
	body = run(&EFVImageConfig{MediaFile: file, Cipher: "aes-256-ctr", Key: "short", IV: strings.Repeat("0", 32)})
	if body["key"] != nil || body["cipher"] != nil {
		t.Fatal("invalid AES settings must not be published")
	}
}
