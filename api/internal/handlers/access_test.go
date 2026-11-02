package handlers

import (
	"database/sql"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"

	"github.com/DATA-DOG/go-sqlmock"
	"github.com/gin-gonic/gin"
	"github.com/jmoiron/sqlx"
	"github.com/yourname/video-api/internal/middleware"
	"github.com/yourname/video-api/internal/repos"
)

func accessTestDB(t *testing.T) (*sqlx.DB, sqlmock.Sqlmock) {
	t.Helper()
	db, mock, err := sqlmock.New()
	if err != nil {
		t.Fatal(err)
	}
	t.Cleanup(func() {
		if err := mock.ExpectationsWereMet(); err != nil {
			t.Error(err)
		}
		db.Close()
	})
	return sqlx.NewDb(db, "sqlmock"), mock
}

func TestChatMembershipAccess(t *testing.T) {
	for _, tc := range []struct {
		name, action, kind string
		member             bool
		status             int
	}{
		{"group outsider cannot mark read", "read", "group", false, 403},
		{"direct outsider cannot mark read", "read", "direct", false, 403},
		{"direct outsider cannot join", "join", "direct", false, 403},
		{"group member can mark read", "read", "group", true, 200},
		{"direct member can mark read", "read", "direct", true, 200},
		{"direct member can join idempotently", "join", "direct", true, 200},
		{"global outsider can mark read", "read", "global", false, 200},
		{"global join needs no membership", "join", "global", false, 200},
		{"group remains open to joining", "join", "group", false, 200},
	} {
		t.Run(tc.name, func(t *testing.T) {
			db, mock := accessTestDB(t)
			mock.ExpectQuery("SELECT .* FROM chat_rooms WHERE id").WithArgs(int64(7)).
				WillReturnRows(sqlmock.NewRows([]string{"id", "kind", "is_active"}).AddRow(7, tc.kind, true))
			if tc.kind != "global" && (tc.action == "read" || tc.kind == "direct") {
				n := 0
				if tc.member {
					n = 1
				}
				mock.ExpectQuery("SELECT COUNT.*FROM chat_room_user").WithArgs(int64(7), int64(42)).
					WillReturnRows(sqlmock.NewRows([]string{"count"}).AddRow(n))
			}
			if tc.status == 200 && !(tc.action == "join" && tc.kind == "global") {
				mock.ExpectExec("INSERT INTO chat_room_user").WithArgs(int64(7), int64(42)).
					WillReturnResult(sqlmock.NewResult(0, 1))
			}
			h := &ChatHandler{Chat: repos.NewChatRepo(db)}
			r := gin.New()
			r.Use(func(c *gin.Context) { c.Set(middleware.CtxUserID, int64(42)) })
			r.POST("/rooms/:id/read", h.MarkRead)
			r.POST("/rooms/:id/join", h.Join)
			w := httptest.NewRecorder()
			r.ServeHTTP(w, httptest.NewRequest(http.MethodPost, "/rooms/7/"+tc.action, nil))
			if w.Code != tc.status {
				t.Fatalf("status = %d, want %d: %s", w.Code, tc.status, w.Body.String())
			}
		})
	}
}

func TestPublicVideoVisibility(t *testing.T) {
	for _, path := range []string{"/videos/7", "/short-videos/7", "/short-videos/7/share"} {
		for _, status := range []string{"draft", "archived", "published", "missing"} {
			t.Run(path+"/"+status, func(t *testing.T) {
				db, mock := accessTestDB(t)
				query := mock.ExpectQuery("SELECT .* FROM videos v").WithArgs(int64(7))
				if status == "missing" {
					query.WillReturnError(sql.ErrNoRows)
				} else {
					query.WillReturnRows(sqlmock.NewRows([]string{"id", "status", "url"}).
						AddRow(7, status, "https://media.example/private.mp4"))
				}
				want := http.StatusNotFound
				if status == "published" {
					want = http.StatusOK
					mock.ExpectExec("UPDATE videos SET").WithArgs(int64(7)).WillReturnResult(sqlmock.NewResult(0, 1))
				}
				video := &VideoHandler{Videos: repos.NewVideoRepo(db)}
				short := &ShortVideoHandler{Repo: repos.NewShortVideoRepo(db)}
				r := gin.New()
				r.GET("/videos/:id", video.Show)
				r.GET("/short-videos/:id", short.Show)
				r.GET("/short-videos/:id/share", short.Share)
				w := httptest.NewRecorder()
				r.ServeHTTP(w, httptest.NewRequest(http.MethodGet, path, nil))
				if w.Code != want {
					t.Fatalf("status = %d, want %d: %s", w.Code, want, w.Body.String())
				}
				if want == http.StatusNotFound && strings.Contains(w.Body.String(), "private.mp4") {
					t.Fatal("unpublished media URL leaked")
				}
			})
		}
	}
}
