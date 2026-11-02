package handlers

import (
	"bytes"
	"crypto/rand"
	"encoding/hex"
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"strings"
	"time"

	"github.com/gin-gonic/gin"
	"github.com/yourname/video-api/internal/bazi"
	"github.com/yourname/video-api/internal/middleware"
	"github.com/yourname/video-api/internal/repos"
)

type BaziHandler struct {
	AnthropicKey string
	Repo         *repos.BaziRepo
}

// chartData is what we store in DB and expose on the public share endpoint.
type chartData struct {
	Birthday string            `json:"birthday"`
	Gender   string            `json:"gender"`
	Pillars  map[string]string `json:"pillars"`
	WuXing   map[string]int    `json:"wuxing"`
	Dayun    []bazi.DayunItem  `json:"dayun"`
	StartAge float64           `json:"start_age"`
	Forward  bool              `json:"forward"`
	Analysis *baziAnalysis     `json:"analysis,omitempty"`
}

type baziAnalysis struct {
	Overall      string `json:"overall"`
	WuXingAdvice string `json:"wuxing_advice"`
	DayunNow     string `json:"dayun_now"`
	Career       string `json:"career"`
	Love         string `json:"love"`
	Advice       string `json:"advice"`
}

type baziCalcResp struct {
	chartData
	ShareToken string `json:"share_token,omitempty"`
}

type baziReq struct {
	Birthday string `json:"birthday" binding:"required"`
	Gender   string `json:"gender" binding:"required"`
}

func (h *BaziHandler) Calculate(c *gin.Context) {
	var req baziReq
	if err := c.ShouldBindJSON(&req); err != nil {
		c.JSON(http.StatusBadRequest, gin.H{"error": "请提供出生时间和性别"})
		return
	}

	birth, err := time.ParseInLocation("2006-01-02 15:04", req.Birthday, time.Local)
	if err != nil {
		c.JSON(http.StatusBadRequest, gin.H{"error": "出生时间格式错误，请使用 YYYY-MM-DD HH:MM"})
		return
	}

	male := req.Gender == "male" || req.Gender == "男"
	chart := bazi.Calculate(birth, male)

	cd := chartData{
		Birthday: req.Birthday,
		Gender:   req.Gender,
		Pillars: map[string]string{
			"year":  chart.Year.GanZhi,
			"month": chart.Month.GanZhi,
			"day":   chart.Day.GanZhi,
			"hour":  chart.Hour.GanZhi,
		},
		WuXing:   chart.WuXing,
		Dayun:    chart.Dayun,
		StartAge: chart.StartAge,
		Forward:  chart.Forward,
	}

	if h.AnthropicKey != "" {
		cd.Analysis = h.aiAnalysis(chart, birth, male)
	}

	resp := baziCalcResp{chartData: cd}

	// Persist and attach share token
	if h.Repo != nil {
		token := newToken()
		raw, _ := json.Marshal(cd)
		uid := middleware.CurrentUserID(c)
		if saveErr := h.Repo.Save(uid, req.Birthday, req.Gender, token, string(raw)); saveErr == nil {
			resp.ShareToken = token
		}
	}

	c.JSON(http.StatusOK, resp)
}

// GetChart serves the public share page — no auth required.
func (h *BaziHandler) GetChart(c *gin.Context) {
	token := c.Param("token")
	if h.Repo == nil {
		c.JSON(http.StatusServiceUnavailable, gin.H{"error": "服务未初始化"})
		return
	}

	row, err := h.Repo.FindByToken(token)
	if err != nil {
		c.JSON(http.StatusInternalServerError, gin.H{"error": "数据库错误"})
		return
	}
	if row == nil {
		c.JSON(http.StatusNotFound, gin.H{"error": "命盘不存在或已过期"})
		return
	}

	var cd chartData
	if err := json.Unmarshal([]byte(row.ChartData), &cd); err != nil {
		c.JSON(http.StatusInternalServerError, gin.H{"error": "数据解析失败"})
		return
	}

	c.JSON(http.StatusOK, gin.H{
		"share_token": token,
		"birthday":    cd.Birthday,
		"gender":      cd.Gender,
		"pillars":     cd.Pillars,
		"wuxing":      cd.WuXing,
		"dayun":       cd.Dayun,
		"start_age":   cd.StartAge,
		"forward":     cd.Forward,
		"analysis":    cd.Analysis,
	})
}

func newToken() string {
	b := make([]byte, 5)
	rand.Read(b)
	return hex.EncodeToString(b) // 10 lowercase hex chars
}

func (h *BaziHandler) aiAnalysis(chart bazi.Chart, birth time.Time, male bool) *baziAnalysis {
	genderStr := "男"
	if !male {
		genderStr = "女"
	}

	age := time.Now().Year() - birth.Year()
	currentDayun := "未入大运"
	for _, d := range chart.Dayun {
		if d.Age <= age {
			currentDayun = d.GanZhi
		}
	}

	var dayunParts []string
	for _, d := range chart.Dayun {
		dayunParts = append(dayunParts, fmt.Sprintf("%d岁%s", d.Age, d.GanZhi))
	}

	direction := "顺行"
	if !chart.Forward {
		direction = "逆行"
	}

	promptData := fmt.Sprintf(
		"性别：%s | 出生：%s | 当前约%d岁\n四柱：年%s 月%s 日%s 时%s（日主：%s）\n五行：木%d 火%d 土%d 金%d 水%d\n大运%s，起运%.1f岁：%s\n当前大运：%s",
		genderStr,
		birth.Format("2006年01月02日 15:04"),
		age,
		chart.Year.GanZhi, chart.Month.GanZhi, chart.Day.GanZhi, chart.Hour.GanZhi,
		chart.Day.Gan,
		chart.WuXing["木"], chart.WuXing["火"], chart.WuXing["土"], chart.WuXing["金"], chart.WuXing["水"],
		direction, chart.StartAge,
		strings.Join(dayunParts, " "),
		currentDayun,
	)

	prompt := "你是精通中国传统命理学的八字大师，请根据以下命盘信息进行深度解读，每个维度80-120字，语言生动具体。\n\n" +
		promptData +
		"\n\n必须严格以以下JSON格式返回，不要包含任何其他内容：\n" +
		`{"overall":"命局总评和日主特质分析","wuxing_advice":"五行喜忌和用神分析","dayun_now":"当前大运机遇与挑战","career":"事业财运方向和适合行业","love":"感情婚姻特质和运势","advice":"三条最重要的人生建议"}`

	payload, _ := json.Marshal(map[string]any{
		"model":      "claude-haiku-4-5",
		"max_tokens": 2048,
		"messages": []map[string]string{
			{"role": "user", "content": prompt},
		},
	})

	httpReq, _ := http.NewRequest("POST", "https://api.anthropic.com/v1/messages", bytes.NewReader(payload))
	httpReq.Header.Set("x-api-key", h.AnthropicKey)
	httpReq.Header.Set("anthropic-version", "2023-06-01")
	httpReq.Header.Set("content-type", "application/json")

	client := &http.Client{Timeout: 60 * time.Second}
	resp, err := client.Do(httpReq)
	if err != nil {
		return nil
	}
	defer resp.Body.Close()

	body, _ := io.ReadAll(resp.Body)
	if resp.StatusCode != 200 {
		return nil
	}

	var apiResp struct {
		Content []struct {
			Text string `json:"text"`
		} `json:"content"`
	}
	if err := json.Unmarshal(body, &apiResp); err != nil || len(apiResp.Content) == 0 {
		return nil
	}

	text := strings.TrimSpace(apiResp.Content[0].Text)
	if idx := strings.Index(text, "{"); idx > 0 {
		text = text[idx:]
	}
	if idx := strings.LastIndex(text, "}"); idx >= 0 {
		text = text[:idx+1]
	}

	var analysis baziAnalysis
	if err := json.Unmarshal([]byte(text), &analysis); err != nil {
		return nil
	}
	return &analysis
}
