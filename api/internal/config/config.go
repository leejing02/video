package config

import (
	"fmt"
	"os"
	"strconv"

	"github.com/joho/godotenv"
)

type Config struct {
	AppEnv     string
	AppPort    string
	DBHost     string
	DBPort     string
	DBUser     string
	DBPassword string
	DBName     string
	DBParams   string
	JWTSecret  string
	JWTTTLHrs  int
	CORS       string
}

// DSN MySQL 连接字符串（go-sql-driver 格式）
func (c *Config) DSN() string {
	return fmt.Sprintf("%s:%s@tcp(%s:%s)/%s?%s",
		c.DBUser, c.DBPassword, c.DBHost, c.DBPort, c.DBName, c.DBParams,
	)
}

func Load() *Config {
	_ = godotenv.Load() // .env 不存在不报错

	ttl, _ := strconv.Atoi(getEnv("JWT_TTL_HOURS", "720"))

	return &Config{
		AppEnv:     getEnv("APP_ENV", "development"),
		AppPort:    getEnv("APP_PORT", "8000"),
		DBHost:     getEnv("DB_HOST", "127.0.0.1"),
		DBPort:     getEnv("DB_PORT", "3306"),
		DBUser:     getEnv("DB_USER", "root"),
		DBPassword: getEnv("DB_PASSWORD", ""),
		DBName:     getEnv("DB_NAME", "video_platform"),
		DBParams:   getEnv("DB_PARAMS", "charset=utf8mb4&parseTime=true&loc=Local"),
		JWTSecret:  getEnv("JWT_SECRET", "change-me"),
		JWTTTLHrs:  ttl,
		CORS:       getEnv("CORS_ORIGINS", "*"),
	}
}

func getEnv(key, def string) string {
	if v := os.Getenv(key); v != "" {
		return v
	}
	return def
}
