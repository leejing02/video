// Package rtp ports CaptureRules.js probability logic to Go.
package rtp

import "math/rand"

const HighPowerCap = 0.9

// BulletCosts maps bullet_level (1-7) to coin cost per shot.
var BulletCosts = map[int]int{
	1: 1, 2: 2, 3: 3, 4: 5, 5: 8, 6: 15, 7: 30,
}

// Multiplier mirrors CaptureRules.getRtpMultiplier.
// coin ≤ 5 → 1.1 (fish is cheap, house edge generous)
// coin 5-50 → linear 1.1 → 0.6
// coin ≥ 50 → 0.6
func Multiplier(coin float64) float64 {
	switch {
	case coin <= 5:
		return 1.1
	case coin >= 50:
		return 0.6
	default:
		t := (coin - 5) / 45.0
		return 1.1 - t*(1.1-0.6)
	}
}

// CaptureChance mirrors CaptureRules.getSingleCaptureChance.
func CaptureChance(bulletPower, fishCoin, accuracyBonus float64) float64 {
	if bulletPower <= 0 || fishCoin <= 0 {
		return 0
	}
	p := (bulletPower / fishCoin) * Multiplier(fishCoin) * (1 + accuracyBonus)
	switch {
	case p > HighPowerCap:
		return HighPowerCap
	case p < 0:
		return 0
	default:
		return p
	}
}

// CheckHit runs a single Bernoulli trial.
func CheckHit(bulletPower, fishCoin, accuracyBonus float64) bool {
	return rand.Float64() < CaptureChance(bulletPower, fishCoin, accuracyBonus)
}
