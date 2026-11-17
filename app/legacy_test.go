package main

import (
	"os"
	"path/filepath"
	"strings"
	"testing"
)

func TestLegacyAddonMigration(t *testing.T) {
	client := t.TempDir()
	old := filepath.Join(client, "Interface", "AddOns", "WoWGuildBank")
	os.MkdirAll(old, 0o755)
	os.WriteFile(filepath.Join(old, "WoWGuildBank.toc"), []byte("## Version: 0.6.1\n"), 0o644)
	sv := filepath.Join(client, "WTF", "Account", "ACC1", "SavedVariables")
	os.MkdirAll(sv, 0o755)
	os.WriteFile(filepath.Join(sv, "WoWGuildBank.lua"), []byte("WoWGuildBankDB = {\n[\"note\"] = \"WoWGuildBankDB = x\",\n}\n"), 0o644)
	if _, err := migrateLegacyAddon(client); err == nil {
		t.Fatal("must refuse while the Banklord addon is missing")
	}
	nw := filepath.Join(client, "Interface", "AddOns", "Banklord")
	os.MkdirAll(nw, 0o755)
	os.WriteFile(filepath.Join(nw, "Banklord.toc"), []byte("## Version: 0.7.0\n"), 0o644)
	msg, err := migrateLegacyAddon(client)
	if err != nil {
		t.Fatal(err)
	}
	b, _ := os.ReadFile(filepath.Join(sv, "Banklord.lua"))
	if !strings.HasPrefix(string(b), "BanklordDB = {") || !strings.Contains(string(b), `"WoWGuildBankDB = x"`) {
		t.Fatalf("saved variables not migrated correctly:\n%s", b)
	}
	if legacyAddonPresent(client) || !strings.Contains(msg, "1 Datensatz") {
		t.Fatalf("old folder still in AddOns or wrong message: %s", msg)
	}
	backups, _ := filepath.Glob(filepath.Join(client, "Banklord-Sicherungen", "WoWGuildBank-*", "WoWGuildBank.toc"))
	if len(backups) != 1 {
		t.Fatal("old addon not backed up")
	}
	os.WriteFile(filepath.Join(sv, "Banklord.lua"), []byte("BanklordDB = {new=true}\n"), 0o644)
	if n, _ := migrateSavedVariables(client); n != 0 {
		t.Fatal("existing Banklord data must never be overwritten")
	}
}
