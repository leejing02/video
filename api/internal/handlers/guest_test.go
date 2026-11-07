package handlers

import (
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"regexp"
	"strings"
	"testing"
	"time"

	"github.com/DATA-DOG/go-sqlmock"
	"github.com/gin-gonic/gin"

	"github.com/yourname/video-api/internal/auth"
	"github.com/yourname/video-api/internal/middleware"
	"github.com/yourname/video-api/internal/repos"
)

const guestTestDevice = "0f8b2c4e-6a1d-4f3b-9c7e-2d5a8b1e4f60"

var userColumns = []string{"id", "name", "username", "email", "email_verified_at", "password", "avatar", "phone", "bio",
	"role", "is_active", "remember_token", "created_at", "updated_at"}

func postGuest(t *testing.T, h *AuthHandler, body string) (*httptest.ResponseRecorder, guestResp) {
	t.Helper()
	gin.SetMode(gin.TestMode)
	r := gin.New()
	r.POST("/guest", h.Guest)
	w := httptest.NewRecorder()
	r.ServeHTTP(w, httptest.NewRequest(http.MethodPost, "/guest", strings.NewReader(body)))
	var resp guestResp
	_ = json.Unmarshal(w.Body.Bytes(), &resp)
	return w, resp
}

func TestGuestRejectsInvalidDevice(t *testing.T) {
	db, _ := accessTestDB(t)
	h := &AuthHandler{Users: repos.NewUserRepo(db), Chat: repos.NewChatRepo(db), JWTSecret: "s"}
	for _, body := range []string{`{}`, `{"device_id":"short"}`, `{"device_id":"` + strings.Repeat("a", 31) + `!"}`} {
		if w, _ := postGuest(t, h, body); w.Code != http.StatusUnprocessableEntity {
			t.Fatalf("%s: got %d", body, w.Code)
		}
	}
}

func TestGuestReusesIdentityUntilItExpires(t *testing.T) {
	db, mock := accessTestDB(t)
	h := &AuthHandler{Users: repos.NewUserRepo(db), Chat: repos.NewChatRepo(db), JWTSecret: "s"}
	email := "guest-" + guestKey("s", guestTestDevice) + guestEmailDomain
	now := time.Now()
	mock.ExpectQuery(`SELECT \* FROM users WHERE email = \?`).WithArgs(email).WillReturnRows(
		sqlmock.NewRows(userColumns).AddRow(7, "游客AB12", "guest_x", email, nil, "h", nil, nil, nil, "user", true, nil, now, now))
	// Created 10 days ago: 20 days remain.
	mock.ExpectQuery(`TIMESTAMPDIFF`).WithArgs(int64(7)).WillReturnRows(sqlmock.NewRows([]string{"age"}).AddRow(int64(10 * 24 * 3600)))

	w, resp := postGuest(t, h, `{"device_id":"`+guestTestDevice+`"}`)
	if w.Code != http.StatusOK || resp.User == nil || resp.User.ID != 7 {
		t.Fatalf("got %d %s", w.Code, w.Body)
	}
	claims, err := auth.Parse("s", resp.Token)
	if err != nil || claims.UserID != 7 || !claims.Guest {
		t.Fatalf("token: %v %+v", err, claims)
	}
	if left := time.Until(claims.ExpiresAt.Time); left < 19*24*time.Hour || left > 20*24*time.Hour+time.Minute {
		t.Fatalf("token must expire 30 days after creation, %v left", left)
	}
	if strings.Contains(w.Body.String(), `"password"`) {
		t.Fatal("password must not be returned")
	}
}

func TestGuestExpiredIdentityIsReplaced(t *testing.T) {
	db, mock := accessTestDB(t)
	h := &AuthHandler{Users: repos.NewUserRepo(db), Chat: repos.NewChatRepo(db), JWTSecret: "s"}
	email := "guest-" + guestKey("s", guestTestDevice) + guestEmailDomain
	now := time.Now()
	mock.ExpectQuery(`SELECT \* FROM users WHERE email = \?`).WithArgs(email).WillReturnRows(
		sqlmock.NewRows(userColumns).AddRow(7, "游客AB12", "guest_x", email, nil, "h", nil, nil, nil, "user", true, nil, now, now))
	mock.ExpectQuery(`TIMESTAMPDIFF`).WithArgs(int64(7)).WillReturnRows(sqlmock.NewRows([]string{"age"}).AddRow(int64(31 * 24 * 3600)))
	mock.ExpectExec(`UPDATE users SET`).WithArgs(int64(7)).WillReturnResult(sqlmock.NewResult(0, 1))
	mock.ExpectExec(`INSERT INTO users`).WillReturnResult(sqlmock.NewResult(8, 1))

	w, resp := postGuest(t, h, `{"device_id":"`+guestTestDevice+`"}`)
	if w.Code != http.StatusOK || resp.User == nil || resp.User.ID != 8 || resp.User.Email != email {
		t.Fatalf("got %d %s", w.Code, w.Body)
	}
	if !regexp.MustCompile(`^[a-z0-9]{10}$`).MatchString(resp.User.Name) {
		t.Fatalf("new guest name %q must be 10 lowercase letters/digits", resp.User.Name)
	}
	if left := time.Until(resp.ExpiresAt); left < 30*24*time.Hour-time.Minute {
		t.Fatalf("new guest must last 30 days, %v left", left)
	}
}

func TestRegisterRejectsGuestAddresses(t *testing.T) {
	db, _ := accessTestDB(t)
	h := &AuthHandler{Users: repos.NewUserRepo(db), Chat: repos.NewChatRepo(db), JWTSecret: "s"}
	gin.SetMode(gin.TestMode)
	r := gin.New()
	r.POST("/register", h.Register)
	for _, body := range []string{
		`{"name":"a","username":"bob","email":"x@guest.invalid","password":"123456"}`,
		`{"name":"a","username":"guest_abc","email":"bob@example.com","password":"123456"}`,
	} {
		w := httptest.NewRecorder()
		r.ServeHTTP(w, httptest.NewRequest(http.MethodPost, "/register", strings.NewReader(body)))
		if w.Code != http.StatusUnprocessableEntity {
			t.Fatalf("%s: got %d", body, w.Code)
		}
	}
}

func TestGroupChatsAreMutedExceptForAllowedSpeakers(t *testing.T) {
	for _, tc := range []struct {
		name, kind   string
		guest        bool
		speaker      bool
		wantMuted    bool
		checkSpeaker bool
	}{
		{"guest in global", "global", true, false, true, true},
		{"registered user in global", "global", false, false, true, true},
		{"registered user in group", "group", false, false, true, true},
		{"allowed speaker in global", "global", false, true, false, true},
		{"allowed guest in group", "group", true, true, false, true},
		{"direct chat is not muted", "direct", false, false, false, false},
	} {
		t.Run(tc.name, func(t *testing.T) {
			db, mock := accessTestDB(t)
			room := func() *sqlmock.Rows {
				return sqlmock.NewRows([]string{"id", "kind", "is_active"}).AddRow(7, tc.kind, true)
			}
			mock.ExpectQuery("SELECT .* FROM chat_rooms WHERE id").WithArgs(int64(7)).WillReturnRows(room())
			if tc.kind != "global" {
				mock.ExpectQuery("SELECT COUNT.*FROM chat_room_user").WithArgs(int64(7), int64(42)).
					WillReturnRows(sqlmock.NewRows([]string{"count"}).AddRow(1))
			}
			mock.ExpectQuery("SELECT .* FROM chat_rooms WHERE id").WithArgs(int64(7)).WillReturnRows(room())
			if tc.checkSpeaker {
				n := 0
				if tc.speaker {
					n = 1
				}
				mock.ExpectQuery("SELECT COUNT.*FROM chat_speakers").WithArgs(int64(42)).
					WillReturnRows(sqlmock.NewRows([]string{"count"}).AddRow(n))
			}
			h := &ChatHandler{Chat: repos.NewChatRepo(db)}
			r := gin.New()
			r.Use(func(c *gin.Context) { c.Set(middleware.CtxUserID, int64(42)); c.Set(middleware.CtxGuest, tc.guest) })
			r.POST("/rooms/:id/messages", h.Send)
			w := httptest.NewRecorder()
			r.ServeHTTP(w, httptest.NewRequest(http.MethodPost, "/rooms/7/messages", strings.NewReader(`{"content":"hi"}`)))
			muted := w.Code == http.StatusForbidden && strings.Contains(w.Body.String(), `"chat_muted"`) && strings.Contains(w.Body.String(), "全体禁言")
			if muted != tc.wantMuted {
				t.Fatalf("status = %d: %s", w.Code, w.Body.String())
			}
		})
	}
}

func TestChatPermissions(t *testing.T) {
	db, mock := accessTestDB(t)
	mock.ExpectQuery("SELECT COUNT.*FROM chat_speakers").WithArgs(int64(42)).WillReturnRows(sqlmock.NewRows([]string{"count"}).AddRow(1))
	mock.ExpectQuery("SELECT COUNT.*FROM chat_speakers").WithArgs(int64(42)).WillReturnError(sqlmock.ErrCancelled)
	h := &ChatHandler{Chat: repos.NewChatRepo(db)}
	r := gin.New()
	r.Use(func(c *gin.Context) { c.Set(middleware.CtxUserID, int64(42)) })
	r.GET("/chat/permissions", h.Permissions)
	for _, want := range []string{`{"can_speak":true}`, `{"can_speak":false}`} {
		w := httptest.NewRecorder()
		r.ServeHTTP(w, httptest.NewRequest(http.MethodGet, "/chat/permissions", nil))
		if w.Code != http.StatusOK || w.Body.String() != want {
			t.Fatalf("got %d %s, want %s", w.Code, w.Body.String(), want)
		}
	}
}

func TestGuestNameIsTenLowercaseLettersOrDigits(t *testing.T) {
	seen := map[string]bool{}
	for i := 0; i < 50; i++ {
		name, err := guestName()
		if err != nil || !regexp.MustCompile(`^[a-z0-9]{10}$`).MatchString(name) {
			t.Fatalf("bad guest name %q (%v)", name, err)
		}
		seen[name] = true
	}
	if len(seen) < 45 {
		t.Fatal("guest names must be random")
	}
}
