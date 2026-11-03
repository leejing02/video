package handlers

import (
	"bytes"
	"encoding/json"
	"fmt"
	"image"
	"image/color"
	"image/draw"
	"image/png"
	"net/http"
	"os"
	"strings"
	"sync"
	"unicode/utf8"

	"github.com/gin-gonic/gin"
	xfont "golang.org/x/image/font"
	"golang.org/x/image/font/basicfont"
	"golang.org/x/image/math/fixed"

	"github.com/yourname/video-api/internal/repos"
)

// OGHandler serves:
//
//	GET /chart/:token        — index.html with OG meta tags injected
//	GET /api/og/chart/:token — 1200×630 PNG preview image
type OGHandler struct {
	Repo        *repos.BaziRepo
	WebDistPath string // path to web/dist directory

	once      sync.Once
	indexHTML []byte
}

// ServeChart returns the Vue SPA's index.html with OG meta tags injected into <head>.
// Social crawlers get the proper preview; real users get the full Vue app.
func (h *OGHandler) ServeChart(c *gin.Context) {
	token := c.Param("token")
	og := h.buildOGMeta(token, c.Request.Host)

	h.once.Do(func() {
		data, err := os.ReadFile(h.indexHTMLPath())
		if err == nil {
			h.indexHTML = data
		}
	})

	c.Header("Content-Type", "text/html; charset=utf-8")
	if len(h.indexHTML) == 0 {
		c.String(http.StatusOK, minimalOGPage(og))
		return
	}
	c.String(http.StatusOK, injectOGTags(string(h.indexHTML), og))
}

// ServeImage returns a 1200×630 PNG visualising the five-element chart.
func (h *OGHandler) ServeImage(c *gin.Context) {
	token := c.Param("token")

	wx := map[string]int{"木": 0, "火": 0, "土": 0, "金": 0, "水": 0}
	var pillars [4]string // year,month,day,hour GanZhi
	birthday := ""

	if h.Repo != nil {
		if row, _ := h.Repo.FindByToken(token); row != nil {
			var cd chartData
			if json.Unmarshal([]byte(row.ChartData), &cd) == nil {
				wx = cd.WuXing
				birthday = cd.Birthday
				pillars[0] = cd.Pillars["year"]
				pillars[1] = cd.Pillars["month"]
				pillars[2] = cd.Pillars["day"]
				pillars[3] = cd.Pillars["hour"]
			}
		}
	}

	img := generateOGImage(wx, pillars, birthday)
	var buf bytes.Buffer
	png.Encode(&buf, img)
	c.Header("Content-Type", "image/png")
	c.Header("Cache-Control", "public, max-age=86400")
	c.Data(http.StatusOK, "image/png", buf.Bytes())
}

func (h *OGHandler) buildOGMeta(token, host string) ogMeta {
	og := ogMeta{
		Title:    "AI 八字命盘",
		Desc:     "用 AI 深度解读你的八字命局、大运与流年",
		ImageURL: "/api/og/chart/" + token,
		URL:      "https://" + host + "/chart/" + token,
	}
	if h.Repo == nil {
		return og
	}
	row, err := h.Repo.FindByToken(token)
	if err != nil || row == nil {
		return og
	}
	var cd chartData
	if json.Unmarshal([]byte(row.ChartData), &cd) != nil {
		return og
	}

	// Extract day Gan (first rune of the GanZhi string)
	dayGan := ""
	if gz := cd.Pillars["day"]; gz != "" {
		r, _ := utf8.DecodeRuneInString(gz)
		dayGan = string(r)
	}

	og.Title = fmt.Sprintf("%s日主 · AI八字命盘", dayGan)
	og.Desc = fmt.Sprintf(
		"年%s 月%s 日%s 时%s · 五行: 木%d火%d土%d金%d水%d · AI深度解读",
		cd.Pillars["year"], cd.Pillars["month"], cd.Pillars["day"], cd.Pillars["hour"],
		cd.WuXing["木"], cd.WuXing["火"], cd.WuXing["土"], cd.WuXing["金"], cd.WuXing["水"],
	)
	return og
}

func (h *OGHandler) indexHTMLPath() string {
	if h.WebDistPath != "" {
		return h.WebDistPath + "/index.html"
	}
	return "./web/dist/index.html"
}

// ─── PNG generator ────────────────────────────────────────────────────────────

const (
	ogW, ogH = 1200, 630
)

var (
	bgColor = color.RGBA{13, 13, 20, 255}

	wxColors = map[string]color.RGBA{
		"木": {34, 197, 94, 255},
		"火": {239, 68, 68, 255},
		"土": {202, 138, 4, 255},
		"金": {156, 163, 175, 255},
		"水": {59, 130, 246, 255},
	}
	ganWX = []string{"木", "木", "火", "火", "土", "土", "金", "金", "水", "水"}
	zhiWX = []string{"水", "土", "木", "木", "土", "火", "火", "土", "金", "金", "土", "水"}
	ganCh = []string{"甲", "乙", "丙", "丁", "戊", "己", "庚", "辛", "壬", "癸"}
	zhiCh = []string{"子", "丑", "寅", "卯", "辰", "巳", "午", "未", "申", "酉", "戌", "亥"}
)

func elementOfGan(g string) string {
	for i, s := range ganCh {
		if s == g {
			return ganWX[i]
		}
	}
	return "土"
}
func elementOfZhi(z string) string {
	for i, s := range zhiCh {
		if s == z {
			return zhiWX[i]
		}
	}
	return "土"
}

func generateOGImage(wx map[string]int, pillars [4]string, birthday string) image.Image {
	img := image.NewRGBA(image.Rect(0, 0, ogW, ogH))
	fillRect(img, 0, 0, ogW, ogH, bgColor)

	// ── top bar ────────────────────────────────────────────────────────
	fillRect(img, 0, 0, ogW, 64, color.RGBA{20, 18, 35, 255})
	ascii(img, 80, 22, "AI  BA ZI  CHART", color.RGBA{160, 130, 240, 255})

	// ── four pillar blocks (left zone) ────────────────────────────────
	labels := []string{"Nian", "Yue", "Ri", "Shi"}
	bW, bH := 110, 120
	lPad, tPad, gap := 80, 110, 30

	for i, gz := range pillars {
		x := lPad + i*(bW+gap)
		runes := []rune(gz)

		ganEl, zhiEl := "土", "土"
		ganStr, zhiStr := "", ""
		if len(runes) >= 1 {
			ganStr = string(runes[0])
			ganEl = elementOfGan(ganStr)
		}
		if len(runes) >= 2 {
			zhiStr = string(runes[1])
			zhiEl = elementOfZhi(zhiStr)
		}
		_ = ganStr
		_ = zhiStr

		// Gan block
		ganCol := wxColors[ganEl]
		roundRect(img, x, tPad, bW, bH, ganCol)
		// Zhi block
		zhiCol := wxColors[zhiEl]
		roundRect(img, x, tPad+bH+12, bW, bH, zhiCol)
		// Label
		ascii(img, x+(bW-len(labels[i])*7)/2, tPad+bH*2+20, labels[i], color.RGBA{150, 150, 170, 255})
	}

	// ── five-element bars (right zone) ────────────────────────────────
	elOrder := []string{"木", "火", "土", "金", "水"}
	maxVal := 1
	for _, v := range wx {
		if v > maxVal {
			maxVal = v
		}
	}
	barX, barMaxW := 700, 380
	barH, barGap, barTop := 38, 20, 115

	for j, el := range elOrder {
		y := barTop + j*(barH+barGap)
		val := wx[el]
		col := wxColors[el]

		fillRect(img, barX, y, barMaxW, barH, color.RGBA{25, 25, 38, 255})
		if val > 0 {
			w := barMaxW * val / (maxVal + 1)
			if w < 12 {
				w = 12
			}
			fillRect(img, barX, y, w, barH, col)
		}
		ascii(img, barX+barMaxW+16, y+barH/2-6, fmt.Sprintf("%d", val), color.RGBA{200, 200, 220, 255})
	}

	// ── bottom strip ──────────────────────────────────────────────────
	stripY := ogH - 72
	fillRect(img, 0, stripY, ogW, 72, color.RGBA{18, 16, 28, 255})
	ascii(img, 80, stripY+24, "birthday: "+birthday, color.RGBA{120, 120, 145, 255})
	ascii(img, ogW-220, stripY+24, "bazi.ai  ·  AI", color.RGBA{90, 90, 115, 255})

	return img
}

func fillRect(img *image.RGBA, x, y, w, h int, c color.RGBA) {
	draw.Draw(img, image.Rect(x, y, x+w, y+h), &image.Uniform{c}, image.Point{}, draw.Src)
}

func roundRect(img *image.RGBA, x, y, w, h int, c color.RGBA) {
	r := 8
	fillRect(img, x+r, y, w-2*r, h, c)
	fillRect(img, x, y+r, w, h-2*r, c)
	// "corners" filled
	fillRect(img, x+r, y, 1, r, c)
	fillRect(img, x+w-r-1, y, 1, r, c)
	fillRect(img, x+r, y+h-r, 1, r, c)
	fillRect(img, x+w-r-1, y+h-r, 1, r, c)
	// subtle bottom shadow
	shadow := color.RGBA{0, 0, 0, 50}
	draw.Draw(img, image.Rect(x, y+h-6, x+w, y+h), &image.Uniform{shadow}, image.Point{}, draw.Over)
}

func ascii(img *image.RGBA, x, y int, s string, c color.RGBA) {
	d := &xfont.Drawer{
		Dst:  img,
		Src:  &image.Uniform{c},
		Face: basicfont.Face7x13,
		Dot:  fixed.P(x, y+13),
	}
	d.DrawString(s)
}

// ─── OG HTML helpers ──────────────────────────────────────────────────────────

type ogMeta struct {
	Title, Desc, ImageURL, URL string
}

func metaTags(og ogMeta) string {
	esc := func(s string) string {
		s = strings.ReplaceAll(s, `"`, `&quot;`)
		s = strings.ReplaceAll(s, `<`, `&lt;`)
		return s
	}
	return fmt.Sprintf(`
  <title>%s</title>
  <meta name="description" content="%s"/>
  <meta property="og:title" content="%s"/>
  <meta property="og:description" content="%s"/>
  <meta property="og:image" content="%s"/>
  <meta property="og:url" content="%s"/>
  <meta property="og:type" content="website"/>
  <meta name="twitter:card" content="summary_large_image"/>
  <meta name="twitter:title" content="%s"/>
  <meta name="twitter:description" content="%s"/>
  <meta name="twitter:image" content="%s"/>`,
		esc(og.Title), esc(og.Desc),
		esc(og.Title), esc(og.Desc), og.ImageURL, og.URL,
		esc(og.Title), esc(og.Desc), og.ImageURL,
	)
}

func injectOGTags(html string, og ogMeta) string {
	if idx := strings.Index(html, "</head>"); idx != -1 {
		return html[:idx] + metaTags(og) + "\n" + html[idx:]
	}
	return html
}

func minimalOGPage(og ogMeta) string {
	return fmt.Sprintf(`<!DOCTYPE html>
<html lang="zh"><head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">%s
</head>
<body style="background:#0d0d14;color:#fff;font-family:system-ui,sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0">
<div style="max-width:440px;text-align:center;padding:40px 20px">
<div style="font-size:52px;margin-bottom:20px">🔯</div>
<h1 style="font-size:22px;margin:0 0 10px">%s</h1>
<p style="color:#9ca3af;font-size:14px;line-height:1.7;margin:0 0 28px">%s</p>
<a href="/" style="display:inline-block;padding:14px 36px;background:#4f46e5;color:#fff;border-radius:14px;text-decoration:none;font-size:15px;font-weight:600">🔯 我也要算命</a>
</div></body></html>`,
		metaTags(og), og.Title, og.Desc)
}
