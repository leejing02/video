package repos

import (
	"errors"
	"github.com/DATA-DOG/go-sqlmock"
	"github.com/jmoiron/sqlx"
	"testing"
)

func TestDirectRejectsSelfAndUnknownPeer(t *testing.T) {
	db, mock, _ := sqlmock.New()
	defer db.Close()
	repo := NewChatRepo(sqlx.NewDb(db, "sqlmock"))
	if _, err := repo.Direct(1, 1, 7); !errors.Is(err, ErrInvalidPeer) {
		t.Fatal("self chat must fail")
	}
	mock.ExpectQuery("SELECT COUNT.*FROM users").WithArgs(int64(7), int64(2)).WillReturnRows(sqlmock.NewRows([]string{"count"}).AddRow(0))
	if _, err := repo.Direct(1, 2, 7); !errors.Is(err, ErrInvalidPeer) {
		t.Fatal("unshared peer must fail")
	}
	if err := mock.ExpectationsWereMet(); err != nil {
		t.Fatal(err)
	}
}

func TestDirectReusesPairAndCommitsBothMembers(t *testing.T) {
	if directSlug(2, 1) != directSlug(1, 2) {
		t.Fatal("pair identity must be symmetric")
	}
	for _, uid := range []int64{1, 2} {
		db, mock, err := sqlmock.New()
		if err != nil {
			t.Fatal(err)
		}
		repo := NewChatRepo(sqlx.NewDb(db, "sqlmock"))
		peer := int64(3) - uid
		mock.ExpectQuery("SELECT COUNT.*FROM users").WithArgs(int64(7), peer).WillReturnRows(sqlmock.NewRows([]string{"count"}).AddRow(1))
		mock.ExpectBegin()
		mock.ExpectExec("INSERT INTO chat_rooms.*ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID").WithArgs("dm-1-2", uid).WillReturnResult(sqlmock.NewResult(9, 1))
		mock.ExpectQuery("SELECT .* FROM chat_rooms WHERE id").WithArgs(int64(9)).WillReturnRows(sqlmock.NewRows([]string{"id", "kind", "is_active"}).AddRow(9, "direct", true))
		mock.ExpectExec("INSERT INTO chat_room_user").WithArgs(int64(9), uid, int64(9), peer).WillReturnResult(sqlmock.NewResult(0, 2))
		mock.ExpectCommit()
		room, err := repo.Direct(uid, peer, 7)
		if err != nil || room.ID != 9 {
			t.Fatalf("direct result: %v %v", room, err)
		}
		if err = mock.ExpectationsWereMet(); err != nil {
			t.Fatal(err)
		}
		db.Close()
	}
}

func TestDirectRollsBackIncompleteMembership(t *testing.T) {
	db, mock, _ := sqlmock.New()
	defer db.Close()
	repo := NewChatRepo(sqlx.NewDb(db, "sqlmock"))
	mock.ExpectQuery("SELECT COUNT.*FROM users").WillReturnRows(sqlmock.NewRows([]string{"count"}).AddRow(1))
	mock.ExpectBegin()
	mock.ExpectExec("INSERT INTO chat_rooms").WillReturnResult(sqlmock.NewResult(9, 1))
	mock.ExpectQuery("SELECT .* FROM chat_rooms WHERE id").WillReturnRows(sqlmock.NewRows([]string{"id", "kind", "is_active"}).AddRow(9, "direct", true))
	mock.ExpectExec("INSERT INTO chat_room_user").WillReturnError(errors.New("write failure"))
	mock.ExpectRollback()
	if _, err := repo.Direct(1, 2, 7); err == nil {
		t.Fatal("must reject incomplete private room")
	}
	if err := mock.ExpectationsWereMet(); err != nil {
		t.Fatal(err)
	}
}

func TestMessagesAfterKeepsForwardOrder(t *testing.T) {
	db, mock, _ := sqlmock.New()
	defer db.Close()
	repo := NewChatRepo(sqlx.NewDb(db, "sqlmock"))
	mock.ExpectQuery("SELECT .*m.id > .*ORDER BY m.id ASC LIMIT").WithArgs(int64(7), int64(100), 100).
		WillReturnRows(sqlmock.NewRows([]string{"id", "chat_room_id", "content"}).AddRow(101, 7, "first").AddRow(102, 7, "second"))
	page, err := repo.MessagesAfter(7, 100, 100)
	if err != nil || len(page) != 2 || page[0].ID != 101 || page[1].ID != 102 {
		t.Fatalf("bad recovery page: %v %v", page, err)
	}
	if err = mock.ExpectationsWereMet(); err != nil {
		t.Fatal(err)
	}
}

func TestMessagesAfterZeroRecoversPreviouslyEmptyConversation(t *testing.T) {
	db, mock, _ := sqlmock.New()
	defer db.Close()
	repo := NewChatRepo(sqlx.NewDb(db, "sqlmock"))
	mock.ExpectQuery("SELECT .*m.id > .*ORDER BY m.id ASC LIMIT").WithArgs(int64(7), int64(0), 100).
		WillReturnRows(sqlmock.NewRows([]string{"id", "chat_room_id", "content"}).AddRow(1, 7, "while offline"))
	page, err := repo.MessagesAfter(7, 0, 100)
	if err != nil || len(page) != 1 {
		t.Fatalf("empty conversation recovery: %v %v", page, err)
	}
	if err = mock.ExpectationsWereMet(); err != nil {
		t.Fatal(err)
	}
}
