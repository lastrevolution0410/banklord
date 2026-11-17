package main

// Migration of installations made under the old project name "WoWGuildBank"
// (up to release 0.9.8). Everything here is idempotent and keeps the old
// files as a backup instead of deleting user data.

import (
	"errors"
	"fmt"
	"os"
	"path/filepath"
	"regexp"
)

const legacyName = "WoWGuildBank"

func legacyAddonDir(client string) string {
	return filepath.Join(client, "Interface", "AddOns", legacyName)
}

func legacyAddonPresent(client string) bool {
	st, err := os.Stat(filepath.Join(legacyAddonDir(client), legacyName+".toc"))
	return err == nil && st.Mode().IsRegular()
}

var legacySVVar = regexp.MustCompile(`(?m)^WoWGuildBankDB(\s*=)`)

// migrateSavedVariables copies WTF/Account/<acc>/SavedVariables/WoWGuildBank.lua
// to Banklord.lua (renaming the global) wherever Banklord.lua does not exist yet.
func migrateSavedVariables(client string) (int, error) {
	files, err := filepath.Glob(filepath.Join(client, "WTF", "Account", "*", "SavedVariables", legacyName+".lua"))
	if err != nil {
		return 0, err
	}
	n := 0
	for _, old := range files {
		dest := filepath.Join(filepath.Dir(old), "Banklord.lua")
		if _, err := os.Stat(dest); err == nil {
			continue
		}
		st, err := os.Lstat(old)
		if err != nil || !st.Mode().IsRegular() || st.Size() > 64<<20 {
			continue
		}
		b, err := os.ReadFile(old)
		if err != nil {
			return n, err
		}
		b = legacySVVar.ReplaceAll(b, []byte("BanklordDB$1"))
		tmp := dest + ".tmp"
		if err = os.WriteFile(tmp, b, 0o644); err != nil {
			return n, err
		}
		if err = os.Rename(tmp, dest); err != nil {
			os.Remove(tmp)
			return n, err
		}
		n++
	}
	return n, nil
}

// migrateLegacyAddon runs after the Banklord addon is installed: it takes over
// the saved data and moves the old addon folder out of AddOns (a copy left
// inside AddOns would load twice). WoW must not be running.
func migrateLegacyAddon(client string) (string, error) {
	if _, err := os.Stat(filepath.Join(client, "Interface", "AddOns", "Banklord", "Banklord.toc")); err != nil {
		return "", errors.New("Banklord-Addon fehlt; Umzug abgebrochen")
	}
	n, err := migrateSavedVariables(client)
	if err != nil {
		return "", fmt.Errorf("Addon-Daten übernehmen: %w", err)
	}
	msg := fmt.Sprintf("Umzug auf Banklord: %d Datensatz/-sätze übernommen", n)
	if legacyAddonPresent(client) {
		backup := filepath.Join(client, "Banklord-Sicherungen")
		if err = os.MkdirAll(backup, 0o755); err != nil {
			return msg, err
		}
		dest := filepath.Join(backup, legacyName+"-"+timestamp())
		if err = os.Rename(legacyAddonDir(client), dest); err != nil {
			return msg, fmt.Errorf("alten Addon-Ordner verschieben: %w", err)
		}
		msg += "; alter Ordner gesichert unter " + dest
	}
	return msg, nil
}
