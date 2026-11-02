package handlers

import (
	"bytes"
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"strings"
	"time"

	"github.com/gin-gonic/gin"
)

type DreamHandler struct {
	AnthropicKey string
}

type dreamRequest struct {
	Description string `json:"description" binding:"required"`
}

type dreamResult struct {
	Traditional string `json:"traditional"`
	Freudian    string `json:"freudian"`
}

const dreamPrompt = `你是一位精通中国传统文化和西方心理学的解梦大师。用户描述了一段梦境，请从两个维度进行详细解析：

【周公解梦】
逐一分析梦中出现的每个重要元素（人物身份、地点、物品、事件经过），结合中国传统周公解梦体系解读各元素的象征意义，最后给出整体吉凶运势指向及生活建议，语言要生动具体，200字左右。

【弗洛伊德及荣格心理学解析】
从潜意识、力比多、原型象征、防御机制、情结等角度，深入分析每个梦境元素的心理象征（如人物代表什么心理投射，场景代表什么心理状态，事件反映了什么内在冲突或愿望），结合弗洛伊德和荣格的理论，揭示梦者当前的深层心理状态、压抑的欲望或成长需求，200字左右。

用户的梦境：%s

必须严格以以下JSON格式返回，不要包含任何其他内容：
{"traditional":"...","freudian":"..."}`

func (h *DreamHandler) Interpret(c *gin.Context) {
	if h.AnthropicKey == "" {
		c.JSON(http.StatusServiceUnavailable, gin.H{"error": "AI解梦服务未配置"})
		return
	}

	var req dreamRequest
	if err := c.ShouldBindJSON(&req); err != nil {
		c.JSON(http.StatusBadRequest, gin.H{"error": "请描述你的梦境"})
		return
	}
	req.Description = strings.TrimSpace(req.Description)
	if len([]rune(req.Description)) < 10 {
		c.JSON(http.StatusBadRequest, gin.H{"error": "梦境描述至少10个字"})
		return
	}
	if len([]rune(req.Description)) > 1000 {
		c.JSON(http.StatusBadRequest, gin.H{"error": "梦境描述不超过1000字"})
		return
	}

	prompt := fmt.Sprintf(dreamPrompt, req.Description)

	payload, _ := json.Marshal(map[string]any{
		"model":      "claude-haiku-4-5",
		"max_tokens": 1024,
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
		c.JSON(http.StatusBadGateway, gin.H{"error": "AI服务请求失败，请稍后重试"})
		return
	}
	defer resp.Body.Close()

	body, _ := io.ReadAll(resp.Body)
	if resp.StatusCode != 200 {
		// 把 Anthropic 的真实错误透传出来，方便排查
		var apiErr struct {
			Error struct {
				Message string `json:"message"`
				Type    string `json:"type"`
			} `json:"error"`
		}
		msg := "AI服务暂时不可用"
		if json.Unmarshal(body, &apiErr) == nil && apiErr.Error.Message != "" {
			msg = apiErr.Error.Message
		}
		c.JSON(http.StatusBadGateway, gin.H{"error": msg})
		return
	}

	var apiResp struct {
		Content []struct {
			Text string `json:"text"`
		} `json:"content"`
	}
	if err := json.Unmarshal(body, &apiResp); err != nil || len(apiResp.Content) == 0 {
		c.JSON(http.StatusInternalServerError, gin.H{"error": "解析AI响应失败"})
		return
	}

	text := strings.TrimSpace(apiResp.Content[0].Text)
	// 容忍 Claude 在 JSON 外包裹 markdown code block
	if idx := strings.Index(text, "{"); idx > 0 {
		text = text[idx:]
	}
	if idx := strings.LastIndex(text, "}"); idx >= 0 {
		text = text[:idx+1]
	}

	var result dreamResult
	if err := json.Unmarshal([]byte(text), &result); err != nil {
		// 降级：把整段文本作为 traditional 返回
		c.JSON(http.StatusOK, dreamResult{Traditional: text, Freudian: ""})
		return
	}
	c.JSON(http.StatusOK, result)
}
