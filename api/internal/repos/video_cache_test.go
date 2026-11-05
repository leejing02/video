package repos

import (
	"regexp"
	"testing"

	"github.com/DATA-DOG/go-sqlmock"
	"github.com/jmoiron/sqlx"
	"github.com/yourname/video-api/internal/videocache"
)

func TestPublicCategoriesUseCacheAndRefresh(t *testing.T) {
	db, mock, err := sqlmock.New()
	if err != nil {
		t.Fatal(err)
	}
	defer db.Close()
	repo := NewShortVideoRepo(sqlx.NewDb(db, "mysql"))
	repo.Cache = videocache.New(t.TempDir(), "test", nil)
	query := regexp.QuoteMeta("SELECT id, name, slug, parent_id, icon, cover, description, sort, is_active FROM categories WHERE type = 'short' AND is_active = 1 ORDER BY sort ASC, id ASC")
	mock.ExpectQuery(query).WillReturnRows(sqlmock.NewRows([]string{"id", "name"}).AddRow(1, "before"))
	for i := 0; i < 100; i++ {
		rows, err := repo.ListCategories()
		if err != nil || len(rows) != 1 || rows[0].Name != "before" {
			t.Fatalf("%v %v", rows, err)
		}
	}
	// Exactly one DB read serves 100 requests; scheduled refresh propagates a deletion.
	mock.ExpectQuery(query).WillReturnRows(sqlmock.NewRows([]string{"id", "name"}))
	repo.Cache.Refresh()
	rows, err := repo.ListCategories()
	if err != nil || len(rows) != 0 {
		t.Fatalf("deleted row survived refresh: %v %v", rows, err)
	}
	if err := mock.ExpectationsWereMet(); err != nil {
		t.Fatal(err)
	}
}
func TestPublicPageFiltersAndPrivateBypass(t *testing.T) {
	db, mock, err := sqlmock.New()
	if err != nil {
		t.Fatal(err)
	}
	defer db.Close()
	repo := NewShortVideoRepo(sqlx.NewDb(db, "mysql"))
	repo.Cache = videocache.New(t.TempDir(), "test", nil)
	expect := func(id int64) {
		mock.ExpectQuery("SELECT COUNT").WithArgs(id).WillReturnRows(sqlmock.NewRows([]string{"total"}).AddRow(0))
		mock.ExpectQuery("SELECT").WithArgs(id, 20, 0).WillReturnRows(sqlmock.NewRows([]string{"id"}))
	}
	for _, id := range []int64{1, 2} {
		expect(id)
		for i := 0; i < 2; i++ {
			_, _, err := repo.List(ListShortVideoFilter{CategoryID: id, Limit: 20})
			if err != nil {
				t.Fatal(err)
			}
		}
	}
	for i := 0; i < 2; i++ {
		expect(42)
		_, _, err := repo.List(ListShortVideoFilter{UserID: 42, Limit: 20, IncludeAll: true})
		if err != nil {
			t.Fatal(err)
		}
	}
	if err := mock.ExpectationsWereMet(); err != nil {
		t.Fatal(err)
	}
}
