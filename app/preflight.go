package main

import (
	"context"
	"errors"
	"fmt"
	"os"
	"os/exec"
	"path/filepath"
	"strings"
)

// A controlled launcher can enforce "verified newest addon before launching".
// Starting through Battle.net or an unrelated desktop shortcut BYPASSES this
// preflight, and offline verification cannot prove that the remote version is
// the latest. We therefore fail closed for this dedicated launch action.
func preflightAndLaunch(ctx context.Context, client string) (string, error) {
	if active, err := wowRunningChecked(); err != nil || active {
		return "", errors.New("WoW läuft bereits oder Prozessstatus unklar; kein sicherer Start")
	}
	found, ok := candidateInstall(client)
	if !ok || !found.Supported {
		return "", errors.New("keine freigegebene MoP-Classic-Installation")
	}
	release, err := fetchRelease(ctx)
	if err != nil {
		return "", fmt.Errorf("Start gesperrt: aktuelle signierte Online-Version nicht prüfbar: %w", err)
	}
	_, err = remoteUpdateClient(ctx, release, client, true)
	if err != nil {
		return "", fmt.Errorf("Start gesperrt: Update konnte nicht abgeschlossen werden: %w", err)
	}
	toc, err := os.ReadFile(filepath.Join(client, "Interface", "AddOns", addonDirectory, "Banklord.toc"))
	if err != nil {
		return "", errors.New("Start gesperrt: Addon fehlt")
	}
	installed := readTOCVersion(toc)
	cmp, ok := compareVersions(installed, release.Addon.Version)
	if !ok || cmp < 0 {
		return "", fmt.Errorf("Start gesperrt: Addon %s, erforderlich %s", installed, release.Addon.Version)
	}
	exe := ""
	for _, candidate := range []string{"WowClassic.exe", "Wow.exe", "Wow-64.exe"} {
		p := filepath.Join(client, candidate)
		if st, e := os.Stat(p); e == nil && st.Mode().IsRegular() {
			exe = p
			break
		}
	}
	if exe == "" {
		return "", errors.New("Spielstarter nicht gefunden – im Battle.net-Client manuell starten")
	}
	cmd := exec.Command(exe)
	cmd.Dir = client
	if err := cmd.Start(); err != nil {
		return "", fmt.Errorf("Spielstart fehlgeschlagen: %w", err)
	}
	// No handle is kept; launcher and the regular background updater are separate.
	_ = cmd.Process.Release()
	return "Vor dem Spielstart geprüft: Addon " + strings.TrimSpace(installed) + ". Spiel gestartet.", nil
}
