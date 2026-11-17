//go:build !windows

package main

import (
	"os"
	"path/filepath"
	"runtime"
	"strings"
	"testing"
)

func TestUnixAutostartRoundTrip(t *testing.T) {
	home := t.TempDir()
	t.Setenv("HOME", home)
	t.Setenv("XDG_CONFIG_HOME", filepath.Join(home, ".config"))
	path := filepath.Join(home, `My "Apps" & $stuff`, "Banklord-Companion")
	if err := setAutostart(path); err != nil {
		t.Fatal(err)
	}
	file, _ := autostartFile(companionAgent)
	b, err := os.ReadFile(file)
	if err != nil {
		t.Fatal(err)
	}
	s := string(b)
	if runtime.GOOS == "darwin" {
		if !strings.Contains(s, "&quot;Apps&quot; &amp; $stuff") || !strings.Contains(s, "<string>--companion</string>") {
			t.Fatalf("plist not escaped: %s", s)
		}
	} else if !strings.Contains(s, `Exec="`+filepath.Join(home, `My \"Apps\" & \$stuff`, "Banklord-Companion")+`" --companion`) {
		t.Fatalf("desktop entry not quoted: %s", s)
	}
	if err := removeAutostart(); err != nil {
		t.Fatal(err)
	}
	if _, err := os.Stat(file); !os.IsNotExist(err) {
		t.Fatal("autostart file not removed")
	}
	if err := removeAutostart(); err != nil {
		t.Fatal("second removal must be a no-op:", err)
	}
}

func TestUnixClientDetection(t *testing.T) {
	root := t.TempDir()
	classic := filepath.Join(root, "World of Warcraft", "_classic_")
	if runtime.GOOS == "darwin" {
		os.MkdirAll(filepath.Join(classic, "World of Warcraft Classic.app", "Contents"), 0o755)
	} else {
		os.MkdirAll(classic, 0o755)
		os.WriteFile(filepath.Join(classic, "WowClassic.exe"), []byte("x"), 0o644)
	}
	os.MkdirAll(filepath.Join(root, "World of Warcraft", "Data"), 0o755)
	in, ok := candidateInstall(classic)
	if !ok || !in.Supported {
		t.Fatalf("client not detected: %+v", in)
	}
	if _, ok := candidateInstall(filepath.Join(root, "World of Warcraft")); ok {
		t.Fatal("root folder must not count as a client")
	}
}

func TestUnixCompanionPayloadIsSelf(t *testing.T) {
	b, err := companionPayload()
	if err != nil || len(b) < 1000 {
		t.Fatalf("payload: %d bytes, %v", len(b), err)
	}
	if exeSuffix != "" {
		t.Fatal("no .exe suffix on unix")
	}
	if strings.HasSuffix(updaterPath(), ".exe") {
		t.Fatal(updaterPath())
	}
}

func TestPgrepNoMatch(t *testing.T) {
	ok, err := pgrep("no-such-process-bl-9f3c2")
	if err != nil || ok {
		t.Fatalf("pgrep: %v %v", ok, err)
	}
}
