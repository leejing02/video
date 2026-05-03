package db

import (
	"time"

	_ "github.com/go-sql-driver/mysql"
	"github.com/jmoiron/sqlx"
)

// MustOpen 打开 MySQL 连接，失败 panic（启动期）
func MustOpen(dsn string) *sqlx.DB {
	db := sqlx.MustConnect("mysql", dsn)
	db.SetMaxOpenConns(50)
	db.SetMaxIdleConns(10)
	db.SetConnMaxLifetime(30 * time.Minute)
	return db
}
