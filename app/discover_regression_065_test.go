package main

import (
	"context"
	"os"
	"path/filepath"
	"strings"
	"testing"
)

// Regression for a standard game installation that the previous 12-second BFS
// could miss if broad root traversal consumed the scan budget.
func Test065PriorityFindsStandardInstall(t *testing.T) {
	root := t.TempDir()
	expected := fakeGame(t, root, "Program Files (x86)/World of Warcraft/_classic_")
	candidates := priorityCandidates(root)
	found := map[string]Install{}
	for _, p := range candidates {
		addAll(found, possibleAt(p))
	}
	if _, ok := found[strings.ToLower(filepath.Clean(expected))]; !ok {
		t.Fatalf("priority search missed standard install %q", expected)
	}
	all := Discover(context.Background(), []string{root})
	if len(all) == 0 || all[0].Path != expected || !all[0].Supported {
		t.Fatalf("Discover missed expected client: %#v", all)
	}
}

func Test065PrivateCustomInstallStillDetected(t *testing.T) {
	root := t.TempDir()
	target := fakeGame(t, root, "Custom Guild/Random Path/_classic_")
	found := Discover(context.Background(), []string{root})
	if len(found) != 1 || found[0].Path != target {
		t.Fatalf("custom installation not found: %#v", found)
	}
}

func Test065AlternativeWowClassicExecutables(t *testing.T) {
	for _, exe := range []string{"WowClassic.exe", "WowClassicT.exe", "WowClassicB.exe", "WowClassic-64.exe"} {
		t.Run(exe, func(t *testing.T) {
			root := t.TempDir()
			dir := filepath.Join(root, "_classic_")
			if err := os.MkdirAll(filepath.Join(dir, "Data"), 0755); err != nil {
				t.Fatal(err)
			}
			if err := os.WriteFile(filepath.Join(dir, exe), []byte("fixture"), 0600); err != nil {
				t.Fatal(err)
			}
			got, ok := candidateInstall(dir)
			if !ok || !got.Supported {
				t.Fatalf("not recognized: %+v", got)
			}
			normalized := normalizeInput(filepath.Join(dir, exe))
			if normalized != dir {
				t.Fatalf("EXE selection resolved to %q", normalized)
			}
		})
	}
}

func Test065DetailedManualSelectionErrors(t *testing.T) {
	root := t.TempDir()
	noPath := filepath.Join(root, "missing")
	if _, err := resolveChoice(noPath); err == nil || !strings.Contains(err.Error(), "existiert nicht") {
		t.Fatalf("missing folder diagnosis: %v", err)
	}
	missingExe := filepath.Join(root, "_classic_")
	if err := os.MkdirAll(filepath.Join(missingExe, "Data"), 0755); err != nil {
		t.Fatal(err)
	}
	if _, err := resolveChoice(missingExe); err == nil || !strings.Contains(err.Error(), "fehlt") {
		t.Fatalf("missing EXE diagnosis: %v", err)
	}
	if err := os.WriteFile(filepath.Join(missingExe, "Wow.exe"), []byte("fixture"), 0600); err != nil {
		t.Fatal(err)
	}
	got, err := resolveChoice(missingExe)
	if err != nil || got != missingExe {
		t.Fatalf("now valid: %q %v", got, err)
	}
}
