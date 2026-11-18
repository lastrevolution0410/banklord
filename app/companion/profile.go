package companion

import (
	"encoding/json"
	"errors"
	"os"
	"path/filepath"
	"runtime"
	"strings"
)

func profilePath() (string, error) {
	dir, err := os.UserConfigDir()
	if err != nil {
		return "", err
	}
	return filepath.Join(dir, "Banklord", "companion.json"), nil
}
func saveProfile(p Profile, token string) error {
	if err := p.validate(); err != nil {
		return err
	}
	if !tokenPattern.MatchString(token) {
		return errors.New("Ungültiges Token")
	}
	sealed, err := protectToken(token)
	if err != nil {
		return err
	}
	p.EncryptedToken = sealed
	dst, err := profilePath()
	if err != nil {
		return err
	}
	if err = os.MkdirAll(filepath.Dir(dst), 0700); err != nil {
		return err
	}
	b, err := json.MarshalIndent(p, "", "  ")
	if err != nil {
		return err
	}
	temporary := dst + ".tmp"
	if err = os.WriteFile(temporary, b, 0600); err != nil {
		return err
	}
	if err = os.Rename(temporary, dst); err != nil {
		os.Remove(temporary)
		return err
	}
	return nil
}
func loadProfile() (Profile, string, error) {
	dst, err := profilePath()
	if err != nil {
		return Profile{}, "", err
	}
	raw, err := os.ReadFile(dst)
	if err != nil {
		return Profile{}, "", err
	}
	var p Profile
	if err = json.Unmarshal(raw, &p); err != nil {
		return p, "", err
	}
	if err = p.validate(); err != nil {
		return p, "", err
	}
	tok, err := unprotectToken(p.EncryptedToken)
	if err != nil {
		return p, "", err
	}
	if !tokenPattern.MatchString(tok) {
		return p, "", errors.New("Token entschlüsselt, aber ungültig")
	}
	return p, tok, nil
}
func discover() []string {
	home, _ := os.UserHomeDir()
	roots := []string{}
	// The unified setup records the exact client path. This is a hint only:
	// uploaded data still requires the normal HTTPS token and server validation.
	if configDir, err := os.UserConfigDir(); err == nil {
		if raw, err := os.ReadFile(filepath.Join(configDir, "Banklord", "known_clients.json")); err == nil {
			var installed []string
			if json.Unmarshal(raw, &installed) == nil {
				for _, path := range installed {
					path = filepath.Clean(path)
					if b := filepath.Base(path); filepath.IsAbs(path) && strings.HasPrefix(b, "_") && strings.HasSuffix(b, "_") {
						roots = append(roots, filepath.Dir(path))
					}
				}
			}
		}
	}

	if runtime.GOOS == "windows" {
		roots = append(roots, filepath.Join(os.Getenv("ProgramFiles(x86)"), "World of Warcraft"), filepath.Join(os.Getenv("ProgramFiles"), "World of Warcraft"))
	}
	if runtime.GOOS == "darwin" {
		roots = append(roots, "/Applications/World of Warcraft", filepath.Join(home, "Applications", "World of Warcraft"))
	}
	roots = append(roots, filepath.Join(home, ".wine", "drive_c", "Program Files (x86)", "World of Warcraft"), filepath.Join(home, "Games", "World of Warcraft"), filepath.Join(home, "World of Warcraft"), filepath.Join(home, "Documents", "World of Warcraft"))
	found := make([]string, 0, 8)
	unique := map[string]bool{}
	for _, root := range roots {
		if root == "" {
			continue
		}
		for _, client := range []string{"_classic_", "_retail_", "_classic_era_", "_anniversary_"} {
			accounts := filepath.Join(root, client, "WTF", "Account")
			list, err := os.ReadDir(accounts)
			if err != nil {
				continue
			}
			for _, folder := range list {
				if !folder.IsDir() {
					continue
				}
				file := filepath.Join(accounts, folder.Name(), "SavedVariables", "Banklord.lua")
				if st, err := os.Stat(file); err == nil && st.Mode().IsRegular() {
					key := strings.ToLower(file)
					if !unique[key] {
						unique[key] = true
						found = append(found, file)
					}
					if len(found) >= 20 {
						return found
					}
				}
			}
		}
	}
	return found
}
