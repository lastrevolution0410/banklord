package companion

import (
	"encoding/json"
	"os"
	"path/filepath"
	"testing"
)

func TestDiscoverInstallerRegisteredUnusualPath(t *testing.T) {
	cfg := t.TempDir()
	t.Setenv("XDG_CONFIG_HOME", cfg)
	root := t.TempDir()
	client := filepath.Join(root, "Very", "Unusual", "Install", "_classic_")
	file := filepath.Join(client, "WTF", "Account", "TestAccount", "SavedVariables", "Banklord.lua")
	if e := os.MkdirAll(filepath.Dir(file), 0755); e != nil {
		t.Fatal(e)
	}
	if e := os.WriteFile(file, []byte("BanklordDB = {}"), 0600); e != nil {
		t.Fatal(e)
	}
	manifest := filepath.Join(cfg, "Banklord", "known_clients.json")
	if e := os.MkdirAll(filepath.Dir(manifest), 0755); e != nil {
		t.Fatal(e)
	}
	b, _ := json.Marshal([]string{client})
	if e := os.WriteFile(manifest, b, 0600); e != nil {
		t.Fatal(e)
	}
	found := discover()
	ok := false
	for _, f := range found {
		if f == file {
			ok = true
		}
	}
	if !ok {
		t.Fatalf("unusual path not discovered: %v", found)
	}
}
