package main

import (
	"archive/zip"
	"bytes"
	"context"
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"strings"
	"testing"
)

func fakeGame(t *testing.T, root, dir string) string {
	t.Helper()
	p := filepath.Join(root, dir)
	if e := os.MkdirAll(filepath.Join(p, "Data"), 0755); e != nil {
		t.Fatal(e)
	}
	if e := os.WriteFile(filepath.Join(p, "Wow.exe"), []byte("fixture"), 0600); e != nil {
		t.Fatal(e)
	}
	return p
}
func TestRecognizesKnownAndPrivateServer(t *testing.T) {
	root := t.TempDir()
	mop := fakeGame(t, root, "World of Warcraft/_classic_")
	private := fakeGame(t, root, "GuildServer/custom-client")
	got, ok := candidateInstall(mop)
	if !ok || !got.Supported {
		t.Fatalf("MoP nicht erkannt: %+v", got)
	}
	got, ok = candidateInstall(private)
	if !ok || got.Supported {
		t.Fatalf("Privatserver fehlerhaft erkannt: %+v", got)
	}
	// Reject random AddOns folders without actual game binary + data.
	dummy := filepath.Join(root, "fake", "Interface", "AddOns")
	os.MkdirAll(dummy, 0755)
	if _, ok := candidateInstall(dummy); ok {
		t.Fatal("fremdes Verzeichnis als Spiel erkannt")
	}
}
func TestNormalizePath(t *testing.T) {
	root := t.TempDir()
	base := fakeGame(t, root, "_classic_")
	if got := normalizeInput(filepath.Join(base, "Interface", "AddOns")); got != base {
		t.Fatalf("got %s want %s", got, base)
	}
	if got := normalizeInput(filepath.Join(base, "Interface")); got != base {
		t.Fatalf("got %s", got)
	}
}
func TestDeepDiscoveryAndMultiInstall(t *testing.T) {
	root := t.TempDir()
	fakeGame(t, root, "Somewhere/Games/Blizzard/World of Warcraft/_classic_")
	fakeGame(t, root, "Other/Legacy/PrivateWoW")
	found := Discover(context.Background(), []string{root})
	if len(found) != 2 {
		t.Fatalf("got %d paths: %#v", len(found), found)
	}
}
func TestAddonInstallAndUpgradeBackup(t *testing.T) {
	root := t.TempDir()
	mop := fakeGame(t, root, "_classic_")
	archive, e := bundled.ReadFile("assets/addon.zip")
	if e != nil {
		t.Fatal(e)
	}
	outcome, e := installAddon(mop, archive)
	if e != nil {
		t.Fatal(e)
	}
	if _, e := os.Stat(filepath.Join(outcome.AddonPath, "Banklord.toc")); e != nil {
		t.Fatal(e)
	}
	external := filepath.Join(mop, "WTF", "Account", "settings.lua")
	os.MkdirAll(filepath.Dir(external), 0755)
	os.WriteFile(external, []byte("keep"), 0600)
	oldPath := filepath.Join(outcome.AddonPath, "UI.lua")
	os.WriteFile(oldPath, []byte("old version"), 0600)
	second, e := installAddon(mop, archive)
	if e != nil {
		t.Fatal(e)
	}
	if second.BackupPath == "" {
		t.Fatal("keine Backup-Dateien angelegt")
	}
	if b, e := os.ReadFile(filepath.Join(second.BackupPath, "UI.lua")); e != nil || string(b) != "old version" {
		t.Fatalf("backup: %v", e)
	}
	if b, e := os.ReadFile(external); e != nil || string(b) != "keep" {
		t.Fatal("WTF verändert")
	}
}
func TestRejectUnsupportedClient(t *testing.T) {
	root := t.TempDir()
	retail := fakeGame(t, root, "_retail_")
	archive, _ := bundled.ReadFile("assets/addon.zip")
	if _, e := installAddon(retail, archive); e == nil {
		t.Fatal("nicht unterstützten Client installiert")
	}
}
func TestArchiveTraversalProtection(t *testing.T) {
	var buf bytes.Buffer
	zw := zip.NewWriter(&buf)
	f, e := zw.Create("Banklord/../../evil.lua")
	if e != nil {
		t.Fatal(e)
	}
	f.Write([]byte("bad"))
	zw.Close()
	target := t.TempDir()
	if e := unpackAddon(buf.Bytes(), target); e == nil {
		t.Fatal("malformed payload accepted")
	}
	if _, e := os.Stat(filepath.Join(target, "evil.lua")); !os.IsNotExist(e) {
		t.Fatal("Archiv hat außerhalb geschrieben")
	}
}
func TestSessionProtection(t *testing.T) {
	w := &wizard{token: "secret", prefix: "/setup/secret"}
	handler := w.secure(func(out http.ResponseWriter, r *http.Request) { out.WriteHeader(204) })
	cases := []struct {
		host, token string
		want        int
	}{
		{"evil.com:1234", "secret", 403},
		{"127.0.0.1:2000", "bad", 403},
		{"127.0.0.1:2000", "secret", 204},
	}
	for _, c := range cases {
		req := httptest.NewRequest(http.MethodPost, "http://127.0.0.1:2000/", strings.NewReader("token="+c.token))
		req.Host = c.host
		req.Header.Set("Content-Type", "application/x-www-form-urlencoded")
		rec := httptest.NewRecorder()
		handler(rec, req)
		if rec.Code != c.want {
			t.Fatalf("host %q token %q got %d", c.host, c.token, rec.Code)
		}
	}
}

func TestRecordClientWithoutSecrets(t *testing.T) {
	dir := isolateConfig(t)
	root := t.TempDir()
	client := fakeGame(t, root, "_classic_")
	if e := recordClientPath(client); e != nil {
		t.Fatal(e)
	}
	if e := recordClientPath(client); e != nil {
		t.Fatal(e)
	}
	data, e := os.ReadFile(filepath.Join(dir, "Banklord", "known_clients.json"))
	if e != nil {
		t.Fatal(e)
	}
	var listed []string
	if e := json.Unmarshal(data, &listed); e != nil || len(listed) != 1 || listed[0] != client {
		t.Fatalf("expected exactly one entry for %s: %s", client, data)
	}
	if strings.Contains(strings.ToLower(string(data)), "token") {
		t.Fatal("manifest contains security credential")
	}
}

func TestResolveMainWoWRoot(t *testing.T) {
	root := t.TempDir()
	folder := filepath.Join(root, "World of Warcraft")
	mop := fakeGame(t, folder, "_classic_")
	actual, e := resolveChoice(folder)
	if e != nil || actual != mop {
		t.Fatalf("root not resolved to MoP client: %q %v", actual, e)
	}
}
