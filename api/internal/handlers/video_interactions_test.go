package handlers

import (
	"encoding/json"
	"github.com/DATA-DOG/go-sqlmock"
	"github.com/gin-gonic/gin"
	"github.com/yourname/video-api/internal/auth"
	"github.com/yourname/video-api/internal/middleware"
	"github.com/yourname/video-api/internal/repos"
	"net/http/httptest"
	"testing"
)

func TestInteractionCountsSharedButStateIsPerAccount(t *testing.T) {
	db, mock := accessTestDB(t)
	h := &ShortVideoHandler{Repo: repos.NewShortVideoRepo(db)}
	r := gin.New()
	r.GET("/videos/:id", middleware.AuthOptional("test-secret"), h.Interactions)
	for _, user := range []int64{1, 2, 1} {
		mock.ExpectQuery("SELECT v.likes").WithArgs(user, user, int64(7)).WillReturnRows(sqlmock.NewRows([]string{"likes", "favorites", "shares", "comments_count", "liked", "favorited"}).AddRow(12, 4, 2, 3, user == 1, user == 1))
		token, err := auth.Issue("test-secret", 1, user, "user")
		if err != nil {
			t.Fatal(err)
		}
		req := httptest.NewRequest("GET", "/videos/7", nil)
		req.Header.Set("Authorization", "Bearer "+token)
		w := httptest.NewRecorder()
		r.ServeHTTP(w, req)
		var got repos.VideoInteractions
		if err := json.Unmarshal(w.Body.Bytes(), &got); err != nil {
			t.Fatal(err)
		}
		if w.Code != 200 || got.Likes != 12 || got.Comments != 3 || got.Favorites != 4 || got.Liked != (user == 1) || got.Favorited != (user == 1) {
			t.Fatalf("user %d: %s", user, w.Body.String())
		}
		if w.Header().Get("Cache-Control") != "private, no-store" {
			t.Fatal("personal state must not be cached")
		}
	}
}

func TestVideoCommentsSharedAcrossAccounts(t *testing.T) {
	db, mock := accessTestDB(t)
	h := &ShortVideoHandler{Repo: repos.NewShortVideoRepo(db)}
	r := gin.New()
	r.GET("/videos/:id/comments", middleware.AuthOptional("test-secret"), h.ListComments)
	for _, user := range []int64{1, 2} {
		mock.ExpectQuery("SELECT c.id, c.user_id").
			WithArgs(int64(7), 20, 0).
			WillReturnRows(sqlmock.NewRows([]string{"id", "user_id", "video_id", "content", "u_id", "u_name", "u_username"}).
				AddRow(31, 1, 7, "shared comment", 1, "Alice", "alice"))
		token, err := auth.Issue("test-secret", 1, user, "user")
		if err != nil {
			t.Fatal(err)
		}
		req := httptest.NewRequest("GET", "/videos/7/comments", nil)
		req.Header.Set("Authorization", "Bearer "+token)
		w := httptest.NewRecorder()
		r.ServeHTTP(w, req)
		var body struct {
			Data []struct {
				ID      int64
				Content string
			}
		}
		if err := json.Unmarshal(w.Body.Bytes(), &body); err != nil {
			t.Fatal(err)
		}
		if w.Code != 200 || len(body.Data) != 1 || body.Data[0].ID != 31 || body.Data[0].Content != "shared comment" {
			t.Fatalf("user %d cannot see shared comment: %s", user, w.Body.String())
		}
		if w.Header().Get("Cache-Control") != "no-store" {
			t.Fatal("comments must not use stale browser cache")
		}
	}
}
