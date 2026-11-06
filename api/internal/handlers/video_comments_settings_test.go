package handlers

import (
	"os"
	"path/filepath"
	"testing"
)

func TestVideoCommentsSettings(t *testing.T) {
	file := filepath.Join(t.TempDir(), "settings.json")
	if !videoCommentsEnabled(file) {
		t.Fatal("missing config must default to enabled")
	}
	for _, tc := range []struct {
		data    string
		enabled bool
	}{
		{`{"enabled":true}`, true}, {`{"enabled":false}`, false}, {`{}`, false}, {`broken`, false},
	} {
		if err := os.WriteFile(file, []byte(tc.data), 0600); err != nil {
			t.Fatal(err)
		}
		if got := videoCommentsEnabled(file); got != tc.enabled {
			t.Fatalf("%s: got %v", tc.data, got)
		}
	}
}
