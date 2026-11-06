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
	source := "/videos/202611/05/6aec7c9ff8bd243144f7b719/cover.webp"
	newVideo := func() models.ShortVideoWithRelations {
		v := models.ShortVideoWithRelations{}
		c, s := synced, source
		v.Status, v.Cover, v.CoverSource = "published", &c, &s
		v.URL = "https://m3u8.example/videos/202611/05/6aec7c9ff8bd243144f7b719/c7e47c/index.m3u8"
		return v
	}

	video := newVideo()
	applyEFVImageCover(&video, image)
	want := "https://tupian.example" + source + "?v=56e89aae0f40"
	if video.Cover == nil || *video.Cover != want || video.CoverFallback == nil || *video.CoverFallback != synced {
		t.Fatalf("expected the stored 封面1 address on the image domain, got %v", video.Cover)
	}
	if video.CoverSource != nil {
		t.Fatal("cover_source must not be exposed in the response")
	}

	for name, mutate := range map[string]func(*models.ShortVideoWithRelations){
		"no stored source": func(v *models.ShortVideoWithRelations) { v.CoverSource = nil },
		"manual cover":     func(v *models.ShortVideoWithRelations) { c := "shorts/covers/manual.jpg"; v.Cover = &c },
		"draft":            func(v *models.ShortVideoWithRelations) { v.Status = "draft" },
		"absolute source":  func(v *models.ShortVideoWithRelations) { s := "https://evil.example/x.webp"; v.CoverSource = &s },
		"path traversal":   func(v *models.ShortVideoWithRelations) { s := "/videos/../admin/x.webp"; v.CoverSource = &s },
	} {
		v := newVideo()
		mutate(&v)
		before := *v.Cover
		applyEFVImageCover(&v, image)
		if *v.Cover != before || v.CoverFallback != nil {
			t.Fatalf("%s: cover must stay local", name)
		}
	}
	v := newVideo()
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
