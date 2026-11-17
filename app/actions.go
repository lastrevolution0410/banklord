package main

import (
	"errors"
	"fmt"
	"os"
	"path/filepath"
)

// performAddonAction re-inspects on each operation, never trusting UI state.
func performAddonAction(client, action string, payload []byte) (string, error) {
	status, err := inspectAddon(client, payload)
	if err != nil {
		return "", err
	}
	if action == "uninstall" {
		if !status.Present {
			return "Addon bereits deinstalliert", nil
		}
		if !status.Recognized {
			return "", errors.New("fremdes/unsicheres Addon wird nicht deinstalliert")
		}
		saved, err := uninstallAddon(client)
		if err != nil {
			return "", err
		}
		if err := removeClientPath(client); err != nil {
			return "Addon entfernt nach " + saved + "; Pfadregistrierung: " + err.Error(), nil
		}
		return "Addon deinstalliert. Wiederherstellbares Archiv: " + saved, nil
	}
	if !status.Present && (action == "auto" || action == "repair") {
		res, err := installAddon(client, payload)
		if err != nil {
			return "", err
		}
		if err := recordClientPath(client); err != nil {
			return "", fmt.Errorf("Addon installiert, Pfadregistrierung fehlgeschlagen: %w", err)
		}
		return "Addon installiert: " + res.AddonPath, nil
	}
	if !status.Recognized {
		return "", errors.New("Zielordner ist kein erkanntes Banklord-Addon; keine Überschreibung")
	}
	if status.NewerInstalled {
		return "", errors.New("neuere installierte Addon-Version: kein Downgrade")
	}
	if action == "auto" {
		cmp, ok := compareVersions(status.InstalledVersion, status.PackagedVersion)
		if !ok {
			return "", errors.New("Addon-Version unbekannt; explizit Reparieren auswählen")
		}
		if cmp == 0 && !status.Modified {
			return "Addon ist bereits aktuell, keine Änderung", nil
		}
	} else if action != "repair" {
		return "", errors.New("unbekannte Aktion")
	}
	res, err := installAddon(client, payload)
	if err != nil {
		return "", err
	}
	if err := recordClientPath(client); err != nil {
		return "Addon ersetzt, Pfadregistrierung fehlgeschlagen: " + err.Error(), nil
	}
	message := "Addon aktualisiert/repariert: " + res.AddonPath
	if res.BackupPath != "" {
		message += "; Sicherung: " + res.BackupPath
	}
	return message, nil
}

func performCompanionAction(action string, payload []byte, autostart, launch bool) (string, error) {
	status, err := inspectCompanion(payload)
	if err != nil {
		return "", err
	}
	if action == "uninstall" {
		saved, err := uninstallCompanion()
		if err != nil {
			return "", err
		}
		if saved == "" {
			return "Companion bereits deinstalliert", nil
		}
		return "Companion deinstalliert; EXE-Sicherung: " + saved + "; Zugangsdaten bleiben für spätere Reinstallation erhalten", nil
	}
	if status.Present && status.NewerInstalled {
		return "", errors.New("neuerer Companion installiert; kein Downgrade")
	}
	if status.Present && !status.Recognized && action == "auto" {
		return "", errors.New("Companion-Version unbekannt; explizit Reparieren auswählen")
	}
	if status.Present && !status.Modified && status.Recognized && status.InstalledVersion == status.PackagedVersion && action == "auto" {
		// Repair user shortcuts even when the executable itself is already current.
		if err := installCompanionShortcuts(status.Path); err != nil {
			return "", fmt.Errorf("Companion ist aktuell, Verknüpfungen fehlgeschlagen: %w", err)
		}
		return "Companion ist aktuell; Desktop- und Startmenü-Verknüpfungen geprüft", nil
	}
	if action != "auto" && action != "repair" {
		return "", errors.New("unbekannte Aktion")
	}
	path, err := installCompanion(payload, autostart, launch)
	if err != nil {
		return "", err
	}
	return "Companion installiert/aktualisiert: " + path, nil
}

// Install manages the executable only, leaving user profiles and DPAPI tokens untouched.
func installCompanion(binary []byte, enableStartup, launch bool) (string, error) {
	return installCompanionWithVersion(binary, bundledCompanionVersion, enableStartup, launch)
}

func installCompanionWithVersion(binary []byte, version string, enableStartup, launch bool) (string, error) {
	if running, why := companionIsRunning(); running {
		return "", fmt.Errorf("Companion läuft noch (%s); bitte vor Update/Reparatur beenden", why)
	}
	dir, err := companionDirectory()
	if err != nil {
		return "", err
	}
	if err := os.MkdirAll(dir, 0700); err != nil {
		return "", err
	}
	target := filepath.Join(dir, "Banklord-Companion"+exeSuffix)
	temp, err := os.CreateTemp(dir, ".bl-companion-*.new")
	if err != nil {
		return "", err
	}
	stage := temp.Name()
	defer os.Remove(stage)
	if _, err = temp.Write(binary); err != nil {
		temp.Close()
		return "", err
	}
	if err = temp.Close(); err != nil {
		return "", err
	}
	if err = os.Chmod(stage, 0o700); err != nil {
		return "", err
	}
	old := ""
	if fi, err := os.Lstat(target); err == nil {
		if !fi.Mode().IsRegular() || fi.Mode()&os.ModeSymlink != 0 {
			return "", errors.New("Companion-Ziel ist keine normale Datei")
		}
		old = filepath.Join(dir, "Banklord-Companion.backup-"+timestamp()+".exe")
		if err = os.Rename(target, old); err != nil {
			return "", fmt.Errorf("Companion läuft oder Datei gesperrt; vor Update bitte beenden: %w", err)
		}
	} else if !os.IsNotExist(err) {
		return "", err
	}
	if err = os.Rename(stage, target); err != nil {
		if old != "" {
			_ = os.Rename(old, target)
		}
		return "", fmt.Errorf("Installation fehlgeschlagen: %w", err)
	}
	if err = writeCompanionManifest(dir, version, hashBytes(binary)); err != nil {
		_ = os.Remove(target)
		if old != "" {
			_ = os.Rename(old, target)
		}
		return "", fmt.Errorf("Companion-Manifest fehlgeschlagen, ursprüngliche EXE zurückgesetzt: %w", err)
	}
	if err = installCompanionShortcuts(target); err != nil {
		return target, fmt.Errorf("Companion installiert, aber Desktop/Startmenü-Verknüpfung fehlgeschlagen: %w", err)
	}
	if enableStartup {
		if err = setAutostart(target); err != nil {
			return target, fmt.Errorf("Companion installiert, aber Autostart konnte nicht aktiviert werden: %w", err)
		}
	}
	if launch {
		if err = launchCompanion(target); err != nil {
			return target, fmt.Errorf("Companion installiert, aber Start fehlgeschlagen: %w", err)
		}
	}
	return target, nil
}
