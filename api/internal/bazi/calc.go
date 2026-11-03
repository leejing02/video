package bazi

import "time"

var Gan = []string{"甲", "乙", "丙", "丁", "戊", "己", "庚", "辛", "壬", "癸"}
var Zhi = []string{"子", "丑", "寅", "卯", "辰", "巳", "午", "未", "申", "酉", "戌", "亥"}

// Five-element mapping for Gan and Zhi
var ganWuXing = []string{"木", "木", "火", "火", "土", "土", "金", "金", "水", "水"}
var zhiWuXing = []string{"水", "土", "木", "木", "土", "火", "火", "土", "金", "金", "土", "水"}

type Pillar struct {
	GanZhi string `json:"ganzhi"`
	Gan    string `json:"gan"`
	Zhi    string `json:"zhi"`
}

type DayunItem struct {
	Age    int    `json:"age"`
	GanZhi string `json:"ganzhi"`
}

type Chart struct {
	Year     Pillar         `json:"year"`
	Month    Pillar         `json:"month"`
	Day      Pillar         `json:"day"`
	Hour     Pillar         `json:"hour"`
	Dayun    []DayunItem    `json:"dayun"`
	StartAge float64        `json:"start_age"`
	WuXing   map[string]int `json:"wuxing"`
	Forward  bool           `json:"forward"`
}

func makePillar(ganIdx, zhiIdx int) Pillar {
	gi := ((ganIdx % 10) + 10) % 10
	zi := ((zhiIdx % 12) + 12) % 12
	return Pillar{GanZhi: Gan[gi] + Zhi[zi], Gan: Gan[gi], Zhi: Zhi[zi]}
}

func gzIndex(ganIdx, zhiIdx int) int {
	gi := ((ganIdx % 10) + 10) % 10
	zi := ((zhiIdx % 12) + 12) % 12
	for i := 0; i < 60; i++ {
		if i%10 == gi && i%12 == zi {
			return i
		}
	}
	return 0
}

// julianDay computes the Julian Day Number for a Gregorian date.
func julianDay(year, month, day int) int {
	a := (14 - month) / 12
	y := year + 4800 - a
	m := month + 12*a - 3
	return day + (153*m+2)/5 + 365*y + y/4 - y/100 + y/400 - 32045
}

// baziYear: the bazi year changes at 立春 (approx Feb 4).
func baziYear(t time.Time) int {
	year := t.Year()
	if t.Before(time.Date(year, 2, 4, 0, 0, 0, 0, t.Location())) {
		return year - 1
	}
	return year
}

// solarMonthSeq returns 0–11 where 0=寅月 (~Feb 4).
// Uses approximate solar term boundaries.
func solarMonthSeq(t time.Time) int {
	m := int(t.Month())
	d := t.Day()

	// [month, day] marking the start of each Chinese solar month
	starts := [][2]int{
		{2, 4}, {3, 6}, {4, 5}, {5, 6}, {6, 6}, {7, 7},
		{8, 8}, {9, 8}, {10, 8}, {11, 8}, {12, 7}, {1, 6},
	}

	// Jan 1–5 is still 子月 of the previous period
	if m == 1 && d < 6 {
		return 10
	}

	seq := 10
	for i := len(starts) - 1; i >= 0; i-- {
		sm, sd := starts[i][0], starts[i][1]
		if m > sm || (m == sm && d >= sd) {
			seq = i
			break
		}
	}
	return seq
}

// calcStartAge calculates the start age for the first major cycle (大运).
// forward=true means the cycle runs forward (顺行).
func calcStartAge(birth time.Time, forward bool) float64 {
	jie := [][2]int{
		{1, 6}, {2, 4}, {3, 6}, {4, 5}, {5, 6}, {6, 6},
		{7, 7}, {8, 8}, {9, 8}, {10, 8}, {11, 8}, {12, 7},
	}
	year := birth.Year()
	loc := birth.Location()

	if forward {
		for _, j := range jie {
			t := time.Date(year, time.Month(j[0]), j[1], 12, 0, 0, 0, loc)
			if t.After(birth) {
				return t.Sub(birth).Hours() / 24 / 3
			}
		}
		t := time.Date(year+1, 1, 6, 12, 0, 0, 0, loc)
		return t.Sub(birth).Hours() / 24 / 3
	}

	var prev time.Time
	for i := len(jie) - 1; i >= 0; i-- {
		t := time.Date(year, time.Month(jie[i][0]), jie[i][1], 12, 0, 0, 0, loc)
		if t.Before(birth) {
			prev = t
			break
		}
	}
	if prev.IsZero() {
		prev = time.Date(year-1, 12, 7, 12, 0, 0, 0, loc)
	}
	return birth.Sub(prev).Hours() / 24 / 3
}

// Calculate builds the full bazi chart for the given birth time and gender.
func Calculate(birth time.Time, male bool) Chart {
	// Year pillar
	by := baziYear(birth)
	yGan := ((by-4)%10 + 10) % 10
	yZhi := ((by-4)%12 + 12) % 12
	yearP := makePillar(yGan, yZhi)

	// Month pillar
	mSeq := solarMonthSeq(birth)
	baseGan := []int{2, 4, 6, 8, 0}[yGan%5]
	mGan := (baseGan + mSeq) % 10
	mZhi := (2 + mSeq) % 12
	monthP := makePillar(mGan, mZhi)

	// Day pillar — reference: JDN(1900-01-01)=2415021 = 甲戌 (GanZhi index 10)
	// offset = 10 - (2415021 % 60) = 10 - 21 = -11 ≡ 49 (mod 60)
	jd := julianDay(birth.Year(), int(birth.Month()), birth.Day())
	dIdx := ((jd+49)%60 + 60) % 60
	dGanIdx := dIdx % 10
	dZhiIdx := dIdx % 12
	dayP := makePillar(dGanIdx, dZhiIdx)

	// Hour pillar
	hour := birth.Hour()
	hZhi := 0
	if hour != 23 {
		hZhi = (hour + 1) / 2
	}
	hBaseGan := []int{0, 2, 4, 6, 8}[dGanIdx%5]
	hGan := (hBaseGan + hZhi) % 10
	hourP := makePillar(hGan, hZhi)

	// Dayun direction: male+yang or female+yin → forward
	yangYear := yGan%2 == 0
	forward := (male && yangYear) || (!male && !yangYear)

	startAge := calcStartAge(birth, forward)

	// 8 Dayun cycles starting from the month after/before the birth month
	baseIdx := gzIndex(mGan, mZhi)
	dayuns := make([]DayunItem, 8)
	for i := 0; i < 8; i++ {
		var idx int
		if forward {
			idx = (baseIdx + i + 1) % 60
		} else {
			idx = ((baseIdx-i-1)%60 + 60) % 60
		}
		dayuns[i] = DayunItem{
			Age:    int(startAge) + i*10,
			GanZhi: Gan[idx%10] + Zhi[idx%12],
		}
	}

	// WuXing: count Gan + Zhi for all four pillars
	wx := map[string]int{"木": 0, "火": 0, "土": 0, "金": 0, "水": 0}
	for _, p := range []Pillar{yearP, monthP, dayP, hourP} {
		for i, g := range Gan {
			if g == p.Gan {
				wx[ganWuXing[i]]++
				break
			}
		}
		for i, z := range Zhi {
			if z == p.Zhi {
				wx[zhiWuXing[i]]++
				break
			}
		}
	}

	return Chart{
		Year:     yearP,
		Month:    monthP,
		Day:      dayP,
		Hour:     hourP,
		Dayun:    dayuns,
		StartAge: startAge,
		WuXing:   wx,
		Forward:  forward,
	}
}
