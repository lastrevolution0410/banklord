package main

import (
	"context"
	"os"
	"path/filepath"
	"strings"
	"testing"
)

// Live installs of Blizzard WoW clients commonly share Data in the parent
// World of Warcraft directory, not inside _classic_/Data.
func Test066SharedParentDataRecognizedAndInstalled(t *testing.T) {
	root := t.TempDir()
	home := filepath.Join(root, "World of Warcraft")
	client := filepath.Join(home, "_classic_")
	if err := os.MkdirAll(filepath.Join(home, "Data"), 0755); err != nil {
		t.Fatal(err)
	}
	if err := os.MkdirAll(client, 0755); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(filepath.Join(client, "WowClassic.exe"), []byte("mock executable"), 0600); err != nil {
		t.Fatal(err)
	}

	if _, err := os.Stat(filepath.Join(client, "Data")); !os.IsNotExist(err) {
		t.Fatalf("fixture unexpectedly has a Data folder in client: %v", err)
	}
	target, ok := candidateInstall(client)
	if !ok || !target.Supported {
		t.Fatalf("shared Data layout not recognized: %+v", target)
	}
	if got, err := resolveChoice(home); err != nil || got != client {
		t.Fatalf("root selection: %q %v", got, err)
	}
	if got, err := resolveChoice(client); err != nil || got != client {
		t.Fatalf("client selection: %q %v", got, err)
	}
	if got, err := resolveChoice(filepath.Join(client, "Interface", "AddOns")); err != nil || got != client {
		t.Fatalf("AddOns selection: %q %v", got, err)
	}
	if got, err := resolveChoice(filepath.Join(client, "WowClassic.exe")); err != nil || got != client {
		t.Fatalf("EXE selection: %q %v", got, err)
	}

	found := Discover(context.Background(), []string{root})
	if len(found) != 1 || found[0].Path != client {
		t.Fatalf("automatic search: %#v", found)
	}
	out, err := installAddon(client, bundleAddon(t))
	if err != nil {
		t.Fatal(err)
	}
	want := filepath.Join(client, "Interface", "AddOns", "Banklord", "Banklord.toc")
	if _, err := os.Stat(want); err != nil {
		t.Fatalf("addon installed to wrong place %q: %v", want, err)
	}
	if out.AddonPath != filepath.Dir(want) {
		t.Fatalf("addon path mismatch %s", out.AddonPath)
	}
	if _, err := os.Stat(filepath.Join(home, "Data")); err != nil {
		t.Fatalf("shared Data touched: %v", err)
	}
}

func Test066LegacyLocalDataAndMissingData(t *testing.T) {
	root := t.TempDir()
	client := filepath.Join(root, "World of Warcraft", "_classic_")
	if err := os.MkdirAll(client, 0755); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(filepath.Join(client, "WowClassic.exe"), []byte("fixture"), 0600); err != nil {
		t.Fatal(err)
	}
	if _, ok := candidateInstall(client); ok {
		t.Fatal("accepted client without any game data")
	}
	if _, err := resolveChoice(client); err == nil || !strings.Contains(err.Error(), "Data fehlt") {
		t.Fatalf("incorrect missing data diagnostic: %v", err)
	}
	if err := os.MkdirAll(filepath.Join(client, "Data"), 0755); err != nil {
		t.Fatal(err)
	}
	if _, ok := candidateInstall(client); !ok {
		t.Fatal("standalone game client rejected")
	}
}

func Test066RetailWithSharedDataNotInstallable(t *testing.T) {
	root := t.TempDir()
	home := filepath.Join(root, "World of Warcraft")
	retail := filepath.Join(home, "_retail_")
	if err := os.MkdirAll(filepath.Join(home, "Data"), 0755); err != nil {
		t.Fatal(err)
	}
	if err := os.MkdirAll(retail, 0755); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(filepath.Join(retail, "Wow.exe"), []byte("fixture"), 0600); err != nil {
		t.Fatal(err)
	}
	got, ok := candidateInstall(retail)
	if !ok || got.Supported {
		t.Fatalf("retail recognition/unsupported state: %+v %v", got, ok)
	}
	if _, err := installAddon(retail, bundleAddon(t)); err == nil {
		t.Fatal("installed MoP addon into retail")
	}
}
