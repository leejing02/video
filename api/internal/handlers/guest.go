package handlers

import (
	"crypto/hmac"
	"crypto/rand"
	"crypto/sha256"
	"encoding/hex"
	"net/http"
	"regexp"
	"strings"
	"time"

	"github.com/gin-gonic/gin"
	"github.com/golang-jwt/jwt/v5"

	"github.com/yourname/video-api/internal/auth"
	"github.com/yourname/video-api/internal/models"
)

// Guests are ordinary users rows bound to one browser/phone: the client keeps a
// random device ID and the server derives the account from it, so the same
// device gets the same identity back. An identity lasts GuestTTL from creation;
// after that the device is given a new one. No schema change is needed.
const (
	GuestTTL         = 30 * 24 * time.Hour
	guestEmailDomain = "@guest.invalid"
)

var guestDeviceID = regexp.MustCompile(`^[A-Za-z0-9-]{32,64}$`)

func IsGuestEmail(email string) bool {
	return strings.HasSuffix(strings.ToLower(email), guestEmailDomain)
}

// guestKey maps a device ID to a stable, non-reversible account key.
func guestKey(secret, device string) string {
	mac := hmac.New(sha256.New, []byte(secret))
	mac.Write([]byte("guest:" + device))
	return hex.EncodeToString(mac.Sum(nil))[:40]
}

func issueGuestToken(secret string, user *models.User, expires time.Time) (string, error) {
	now := time.Now()
	claims := auth.Claims{
		UserID: user.ID,
		Role:   user.Role,
		Guest:  true,
		RegisteredClaims: jwt.RegisteredClaims{
			IssuedAt:  jwt.NewNumericDate(now),
			ExpiresAt: jwt.NewNumericDate(expires),
			Issuer:    "video-api",
		},
	}
	return jwt.NewWithClaims(jwt.SigningMethodHS256, claims).SignedString([]byte(secret))
}

const guestNameChars = "abcdefghijklmnopqrstuvwxyz0123456789"

// guestName is a random 10-character nickname of lowercase letters and digits.
func guestName() (string, error) {
	buf := make([]byte, 10)
	if _, err := rand.Read(buf); err != nil {
		return "", err
	}
	for i, b := range buf {
		buf[i] = guestNameChars[int(b)%len(guestNameChars)]
	}
	return string(buf), nil
}

type guestReq struct {
	DeviceID string `json:"device_id"`
}

type guestResp struct {
	User      *models.User `json:"user"`
	Token     string       `json:"token"`
	ExpiresAt time.Time    `json:"expires_at"`
}

// Guest returns this device's temporary identity, creating it when needed.
func (h *AuthHandler) Guest(c *gin.Context) {
	var req guestReq
	if err := c.ShouldBindJSON(&req); err != nil || !guestDeviceID.MatchString(req.DeviceID) {
		c.JSON(http.StatusUnprocessableEntity, gin.H{"error": "设备标识无效"})
		return
	}
	key := guestKey(h.JWTSecret, req.DeviceID)
	email := "guest-" + key + guestEmailDomain

	user, err := h.Users.FindByEmail(email)
	if err != nil {
		c.JSON(http.StatusInternalServerError, gin.H{"error": "游客身份获取失败"})
		return
	}
	var expires time.Time
	if user != nil {
		age, err := h.Users.AgeSeconds(user.ID)
		if err != nil {
			c.JSON(http.StatusInternalServerError, gin.H{"error": "游客身份获取失败"})
			return
		}
		remaining := GuestTTL - time.Duration(age)*time.Second
		if user.IsActive && remaining > time.Minute {
			expires = time.Now().Add(remaining)
		} else {
			// Expired (or disabled): free the device key and start a new identity.
			if err := h.Users.RetireGuest(user.ID); err != nil {
				c.JSON(http.StatusInternalServerError, gin.H{"error": "游客身份获取失败"})
				return
			}
			user = nil
		}
	}
	if user == nil {
		password := make([]byte, 24)
		if _, err := rand.Read(password); err != nil {
			c.JSON(http.StatusInternalServerError, gin.H{"error": "游客身份获取失败"})
			return
		}
		// Unusable password: guests cannot sign in with email/password.
		hash, err := auth.HashPassword(hex.EncodeToString(password))
		if err != nil {
			c.JSON(http.StatusInternalServerError, gin.H{"error": "游客身份获取失败"})
			return
		}
		name, err := guestName()
		if err != nil {
			c.JSON(http.StatusInternalServerError, gin.H{"error": "游客身份获取失败"})
			return
		}
		user = &models.User{
			Name:     name,
			Username: "guest_" + key[:12],
			Email:    email,
			Password: hash,
			Role:     "user",
			IsActive: true,
		}
		if err := h.Users.Create(user); err != nil {
			c.JSON(http.StatusInternalServerError, gin.H{"error": "游客身份获取失败"})
			return
		}
		if room, _ := h.Chat.GlobalRoom(); room != nil {
			_ = h.Chat.Join(room.ID, user.ID)
		}
		expires = time.Now().Add(GuestTTL)
		if created, err := h.Users.FindByID(user.ID); err == nil && created != nil {
			user = created
		}
	}

	token, err := issueGuestToken(h.JWTSecret, user, expires)
	if err != nil {
		c.JSON(http.StatusInternalServerError, gin.H{"error": "游客身份获取失败"})
		return
	}
	c.JSON(http.StatusOK, guestResp{User: user, Token: token, ExpiresAt: expires.UTC()})
}
