package repos

import (
	"github.com/DATA-DOG/go-sqlmock"
	"github.com/jmoiron/sqlx"
	"testing"
)

func TestRepliesRemainScopedToVideoAndParentAtAnyDepth(t *testing.T) {
	db, mock, err := sqlmock.New()
	if err != nil {
		t.Fatal(err)
	}
	defer db.Close()
	repo := NewShortVideoRepo(sqlx.NewDb(db, "mysql"))
	mock.ExpectQuery(`WHERE c.video_id = \? AND c.parent_id = \?`).WithArgs(int64(7), int64(9999), 20, 40).WillReturnRows(sqlmock.NewRows([]string{"id", "parent_id", "content", "u_name"}).AddRow(10000, 9999, "deep reply", "reader"))
	rows, err := repo.ListComments(7, 20, 40, 9999)
	if err != nil || len(rows) != 1 || rows[0].Content != "deep reply" {
		t.Fatalf("%v %v", rows, err)
	}
	if err := mock.ExpectationsWereMet(); err != nil {
		t.Fatal(err)
	}
}
