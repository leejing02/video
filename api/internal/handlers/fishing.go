package handlers

import (
	"math/rand"
	"net/http"
	"sync"

	"github.com/gin-gonic/gin"
	"github.com/yourname/video-api/internal/middleware"
)

// fishTypes maps fish_type_id → coin value
var fishTypes = map[int]int{
	1: 2, 2: 3, 3: 5, 4: 8, 5: 10, 6: 20,
	7: 30, 8: 40, 9: 50, 10: 60, 11: 100, 12: 200,
}

// bulletCosts maps bullet_level (1-7) → coin cost per shot
var bulletCosts = map[int]int{
	1: 1, 2: 2, 3: 3, 4: 5, 5: 8, 6: 15, 7: 30,
}

const highPowerCap = 0.9

// in-memory coin ledger (userID → balance); replace with DB when ready
var (
	balanceMu sync.RWMutex
	balances  = map[int64]int{}
)

const startBalance = 1000

func getBalance(uid int64) int {
	balanceMu.RLock()
	b, ok := balances[uid]
	balanceMu.RUnlock()
	if !ok {
		balanceMu.Lock()
		balances[uid] = startBalance
		b = startBalance
		balanceMu.Unlock()
	}
	return b
}

func addBalance(uid int64, delta int) int {
	balanceMu.Lock()
	defer balanceMu.Unlock()
	if _, ok := balances[uid]; !ok {
		balances[uid] = startBalance
	}
	balances[uid] += delta
	return balances[uid]
}

// rtpMultiplier mirrors CaptureRules.getRtpMultiplier
func rtpMultiplier(coin float64) float64 {
	if coin <= 5 {
		return 1.1
	}
	if coin >= 50 {
		return 0.6
	}
	t := (coin - 5) / 45.0
	return 1.1 - t*(1.1-0.6)
}

// captureChance mirrors CaptureRules.getSingleCaptureChance
func captureChance(bulletPower, fishCoin float64, accuracyBonus float64) float64 {
	if bulletPower <= 0 || fishCoin <= 0 {
		return 0
	}
	base := (bulletPower / fishCoin) * rtpMultiplier(fishCoin)
	p := base * (1 + accuracyBonus)
	if p > highPowerCap {
		return highPowerCap
	}
	if p < 0 {
		return 0
	}
	return p
}

type FishingHandler struct{}

type FireRequest struct {
	BulletLevel    int     `json:"bullet_level" binding:"required,min=1,max=7"`
	FishTypeID     int     `json:"fish_type_id" binding:"required"`
	AccuracyFactor float64 `json:"accuracy_factor"`
}

type FireResponse struct {
	MsgID      int  `json:"msg_id"`
	Hit        bool `json:"hit"`
	Reward     int  `json:"reward"`
	BulletCost int  `json:"bullet_cost"`
	Balance    int  `json:"balance"`
}

func (h *FishingHandler) Fire(c *gin.Context) {
	uid := middleware.CurrentUserID(c) // int64

	var req FireRequest
	if err := c.ShouldBindJSON(&req); err != nil {
		c.JSON(http.StatusBadRequest, gin.H{"error": err.Error()})
		return
	}

	coin, ok := fishTypes[req.FishTypeID]
	if !ok {
		c.JSON(http.StatusBadRequest, gin.H{"error": "unknown fish_type_id"})
		return
	}

	cost := bulletCosts[req.BulletLevel]
	bal := getBalance(uid)
	if bal < cost {
		c.JSON(http.StatusPaymentRequired, gin.H{"error": "insufficient coins", "balance": bal})
		return
	}

	// deduct bullet cost first
	bal = addBalance(uid, -cost)

	prob := captureChance(float64(req.BulletLevel), float64(coin), req.AccuracyFactor)
	hit := rand.Float64() < prob

	reward := 0
	if hit {
		reward = coin
		bal = addBalance(uid, reward)
	}

	c.JSON(http.StatusOK, FireResponse{
		MsgID:      1002, // protocol.MsgHit
		Hit:        hit,
		Reward:     reward,
		BulletCost: cost,
		Balance:    bal,
	})
}

func (h *FishingHandler) Balance(c *gin.Context) {
	uid := middleware.CurrentUserID(c)
	c.JSON(http.StatusOK, gin.H{"balance": getBalance(uid)})
}
