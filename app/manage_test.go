package main

import (
	"os"
	"path/filepath"
	"strings"
	"testing"
)

func bundleAddon(t *testing.T) []byte {
	t.Helper()
	b, e := bundled.ReadFile("assets/addon.zip")
	if e != nil {
		t.Fatal(e)
	}
	return b
}

// packagedAddonVersion reads the version from the embedded .toc, so tests follow releases.
func packagedAddonVersion(t *testing.T) string {
	t.Helper()
	files, err := addonPayloadFiles(bundleAddon(t))
	if err != nil {
		t.Fatal(err)
	}
	return readTOCVersion(files["Banklord.toc"])
}

func setupCompanionFixture(t *testing.T) {
	t.Helper()
	dir := t.TempDir()
	t.Setenv("LOCALAPPDATA", dir)
	t.Setenv("XDG_CONFIG_HOME", dir)
}

func TestSmartInstallUpdateRepairUninstall(t *testing.T) {
	setupCompanionFixture(t)
	client := fakeGame(t, t.TempDir(), "_classic_")
	payload := bundleAddon(t)
	status, e := inspectAddon(client, payload)
	if e != nil || status.Present || status.Suggestion != "Installieren" {
		t.Fatalf("initial %+v %v", status, e)
	}
	if _, e := performAddonAction(client, "auto", payload); e != nil {
		t.Fatal(e)
	}
	status, e = inspectAddon(client, payload)
	if e != nil || status.Modified || status.InstalledVersion != packagedAddonVersion(t) {
		t.Fatalf("after install %+v %v", status, e)
	}
	if _, e := performAddonAction(client, "auto", payload); e != nil {
		t.Fatal(e)
	}
	backupDir := filepath.Join(client, "Banklord-Sicherungen")
	if _, e := os.Stat(backupDir); !os.IsNotExist(e) {
		t.Fatal("idempotent install created backups")
	}
	addon := filepath.Join(client, "Interface", "AddOns", "Banklord")
	toc := filepath.Join(addon, "Banklord.toc")
	b, e := os.ReadFile(toc)
	if e != nil {
		t.Fatal(e)
	}
	if e := os.WriteFile(toc, []byte(strings.Replace(string(b), "## Version: "+packagedAddonVersion(t), "## Version: 0.5.1-dev", 1)), 0644); e != nil {
		t.Fatal(e)
	}
	status, _ = inspectAddon(client, payload)
	if status.Condition != "Update verfügbar" {
		t.Fatalf("old version not detected: %+v", status)
	}
	if _, e := performAddonAction(client, "auto", payload); e != nil {
		t.Fatal(e)
	}
	status, _ = inspectAddon(client, payload)
	if status.Modified || status.InstalledVersion != packagedAddonVersion(t) {
		t.Fatalf("update failed: %+v", status)
	}
	if e := os.WriteFile(filepath.Join(addon, "UI.lua"), []byte("corrupt"), 0644); e != nil {
		t.Fatal(e)
	}
	status, _ = inspectAddon(client, payload)
	if status.Condition != "Dateien abweichend oder beschädigt" {
		t.Fatalf("corruption not detected: %+v", status)
	}
	if _, e := performAddonAction(client, "auto", payload); e != nil {
		t.Fatal(e)
	}
	status, _ = inspectAddon(client, payload)
	if status.Modified {
		t.Fatal("auto repair did not fix corrupted file")
	}
	// Uninstall is limited to our addon; do not touch other addons or saved variables.
	other := filepath.Join(client, "Interface", "AddOns", "AnotherAddon", "AnotherAddon.toc")
	saved := filepath.Join(client, "WTF", "Account", "A", "SavedVariables", "Banklord.lua")
	for _, p := range []string{other, saved} {
		os.MkdirAll(filepath.Dir(p), 0755)
		os.WriteFile(p, []byte("keep"), 0600)
	}
	msg, e := performAddonAction(client, "uninstall", payload)
	if e != nil || !strings.Contains(msg, "deinstalliert") {
		t.Fatalf("uninstall %s %v", msg, e)
	}
	if _, e := os.Stat(addon); !os.IsNotExist(e) {
		t.Fatal("addon still active")
	}
	for _, p := range []string{other, saved} {
		if b, e := os.ReadFile(p); e != nil || string(b) != "keep" {
			t.Fatalf("modified %q: %v", p, e)
		}
	}
	if _, e := performAddonAction(client, "uninstall", payload); e != nil {
		t.Fatal("repeated uninstall must be safe", e)
	}
}

func TestDowngradeAndForeignProtection(t *testing.T) {
	setupCompanionFixture(t)
	client := fakeGame(t, t.TempDir(), "_classic_")
	payload := bundleAddon(t)
	if _, e := performAddonAction(client, "auto", payload); e != nil {
		t.Fatal(e)
	}
	toc := filepath.Join(client, "Interface", "AddOns", addonDirectory, "Banklord.toc")
	b, _ := os.ReadFile(toc)
	if e := os.WriteFile(toc, []byte(strings.Replace(string(b), "## Version: "+packagedAddonVersion(t), "## Version: 9.9.9", 1)), 0644); e != nil {
		t.Fatal(e)
	}
	if _, e := performAddonAction(client, "auto", payload); e == nil {
		t.Fatal("downgrade auto accepted")
	}
	if _, e := performAddonAction(client, "repair", payload); e == nil {
		t.Fatal("downgrade repair accepted")
	}
	if e := os.WriteFile(toc, []byte("## Title: Totally Different Addon\n## Version: 0.1\n"), 0644); e != nil {
		t.Fatal(e)
	}
	if _, e := performAddonAction(client, "uninstall", payload); e == nil {
		t.Fatal("foreign addon was deleted")
	}
	if _, e := performAddonAction(client, "repair", payload); e == nil {
		t.Fatal("foreign addon was overwritten")
	}
}

func TestCompanionModesAndPreserveSecrets(t *testing.T) {
	setupCompanionFixture(t)
	exe := []byte("test-executable-1")
	st, e := inspectCompanion(exe)
	if e != nil || st.Present {
		t.Fatalf("not absent: %+v %v", st, e)
	}
	if _, e := performCompanionAction("auto", exe, false, false); e != nil {
		t.Fatal(e)
	}
	st, e = inspectCompanion(exe)
	if e != nil || !st.Recognized || st.Modified {
		t.Fatalf("manifest invalid: %+v %v", st, e)
	}
	dir, e := companionDirectory()
	if e != nil {
		t.Fatal(e)
	}
	profile := filepath.Join(dir, "credentials.secured")
	os.WriteFile(profile, []byte("keep encrypted data"), 0600)
	msg, e := performCompanionAction("auto", exe, false, false)
	if e != nil || !strings.Contains(msg, "aktuell") {
		t.Fatalf("idempotency: %s %v", msg, e)
	}
	newer := []byte("test-executable-2")
	msg, e = performCompanionAction("repair", newer, false, false)
	if e != nil {
		t.Fatal(e)
	}
	if !strings.Contains(msg, "installiert") {
		t.Fatal(msg)
	}
	if _, e := performCompanionAction("uninstall", newer, false, false); e != nil {
		t.Fatal(e)
	}
	st, _ = inspectCompanion(newer)
	if st.Present {
		t.Fatal("companion not removed")
	}
	b, e := os.ReadFile(profile)
	if e != nil || string(b) != "keep encrypted data" {
		t.Fatal("user settings deleted")
	}
}

func TestVersionOrdering(t *testing.T) {
	cases := []struct {
		a, b string
		want int
	}{
		{"0.5.1-dev", "0.5.2-dev", -1}, {"0.5.2-dev", "0.5.2-dev", 0},
		{"0.5.2", "0.5.2-dev", 1}, {"1.0.0", "0.9.9", 1},
	}
	for _, c := range cases {
		got, ok := compareVersions(c.a, c.b)
		if !ok || got != c.want {
			t.Fatalf("%q vs %q: %d %v", c.a, c.b, got, ok)
		}
	}
	if _, ok := compareVersions("abc", "1.0.0"); ok {
		t.Fatal("accepted unknown version")
	}
}
