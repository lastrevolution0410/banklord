package main

import (
	"archive/zip"
	"bytes"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"os"
	"path/filepath"
	"regexp"
	"strconv"
	"strings"
	"time"
)

const setupVersion = "1.3.0"
const bundledCompanionVersion = "0.9.0"

// ComponentStatus is a read-only inspection. Installation decisions are derived anew on POST.
type ComponentStatus struct {
	Name, Path, InstalledVersion, PackagedVersion, Condition, Suggestion string
	Present, Modified, NewerInstalled, Recognized                        bool
}

type componentManifest struct {
	Version string `json:"version"`
	SHA256  string `json:"sha256"`
}

func hashBytes(b []byte) string { a := sha256.Sum256(b); return hex.EncodeToString(a[:]) }

func readTOCVersion(data []byte) string {
	for _, line := range strings.Split(string(data), "\n") {
		if strings.HasPrefix(strings.ToLower(strings.TrimSpace(line)), "## version:") {
			return strings.TrimSpace(strings.SplitN(line, ":", 2)[1])
		}
	}
	return ""
}

var versionRegexp = regexp.MustCompile(`^(\d+)\.(\d+)\.(\d+)(?:[-+]([a-zA-Z0-9._-]+))?$`)

// compareVersions returns 1 when a > b, -1 when a < b, 0 for equal and ok=false for unrecognized versions.
func compareVersions(a, b string) (cmp int, ok bool) {
	m1, m2 := versionRegexp.FindStringSubmatch(strings.TrimSpace(a)), versionRegexp.FindStringSubmatch(strings.TrimSpace(b))
	if m1 == nil || m2 == nil {
		return 0, false
	}
	for i := 1; i <= 3; i++ {
		n1, _ := strconv.Atoi(m1[i])
		n2, _ := strconv.Atoi(m2[i])
		if n1 < n2 {
			return -1, true
		}
		if n1 > n2 {
			return 1, true
		}
	}
	if m1[4] == m2[4] {
		return 0, true
	}
	if m1[4] == "" {
		return 1, true
	}
	if m2[4] == "" {
		return -1, true
	}
	if m1[4] < m2[4] {
		return -1, true
	}
	return 1, true
}

func addonPayloadFiles(payload []byte) (map[string][]byte, error) {
	r, err := zip.NewReader(bytes.NewReader(payload), int64(len(payload)))
	if err != nil {
		return nil, err
	}
	files := map[string][]byte{}
	for _, f := range r.File {
		if f.FileInfo().IsDir() {
			continue
		}
		n := strings.ReplaceAll(f.Name, "\\", "/")
		if !strings.HasPrefix(n, "Banklord/") {
			continue
		}
		rel := strings.TrimPrefix(n, "Banklord/")
		if rel == "" || strings.Contains(rel, "/") || strings.Contains(rel, "..") {
			continue
		}
		if !strings.HasSuffix(strings.ToLower(rel), ".lua") && !strings.HasSuffix(strings.ToLower(rel), ".toc") && rel != "GoldBars.tga" {
			continue
		}
		if f.UncompressedSize64 > 2<<20 {
			return nil, errors.New("Addon-Payload überschreitet Größenlimit")
		}
		rc, e := f.Open()
		if e != nil {
			return nil, e
		}
		b, e := io.ReadAll(io.LimitReader(rc, (2<<20)+1))
		rc.Close()
		if e != nil {
			return nil, e
		}
		if len(b) > 2<<20 {
			return nil, errors.New("Addon-Payload zu groß")
		}
		files[rel] = b
	}
	if len(files) < 4 || len(files["Banklord.toc"]) == 0 {
		return nil, errors.New("Addon-Payload unvollständig")
	}
	return files, nil
}

func inspectAddon(client string, payload []byte) (ComponentStatus, error) {
	files, err := addonPayloadFiles(payload)
	if err != nil {
		return ComponentStatus{}, err
	}
	dest := filepath.Join(client, "Interface", "AddOns", addonDirectory)
	status := ComponentStatus{Name: "WoW-Addon", Path: dest, PackagedVersion: readTOCVersion(files["Banklord.toc"]), Condition: "Nicht installiert", Suggestion: "Installieren"}
	fi, err := os.Lstat(dest)
	if os.IsNotExist(err) {
		return status, nil
	}
	if err != nil {
		return status, err
	}
	status.Present = true
	if !fi.IsDir() || fi.Mode()&os.ModeSymlink != 0 {
		status.Condition = "Unsicheres Ziel (Link/Datei)"
		status.Suggestion = "Nicht verändern"
		return status, nil
	}
	toc, err := os.ReadFile(filepath.Join(dest, "Banklord.toc"))
	if err != nil || !strings.Contains(strings.ToLower(string(toc)), "## title: banklord") {
		status.Condition = "Ordner nicht als Banklord erkannt"
		status.Suggestion = "Nicht verändern"
		return status, nil
	}
	status.Recognized = true
	status.InstalledVersion = readTOCVersion(toc)
	mismatches := 0
	for rel, expected := range files {
		b, e := os.ReadFile(filepath.Join(dest, rel))
		if rel == "Banklord.toc" {
			// The installer pins the client's interface number; ignore that line.
			b, expected = tocInterfaceRE.ReplaceAll(b, nil), tocInterfaceRE.ReplaceAll(expected, nil)
		}
		if e != nil || hashBytes(b) != hashBytes(expected) {
			mismatches++
		}
	}
	status.Modified = mismatches > 0
	cmp, known := compareVersions(status.InstalledVersion, status.PackagedVersion)
	switch {
	case !known:
		status.Condition = "Version unbekannt"
		status.Suggestion = "Manuell prüfen / reparieren"
	case cmp > 0:
		status.NewerInstalled = true
		status.Condition = "Installierte Version ist neuer"
		status.Suggestion = "Kein Downgrade"
	case cmp < 0:
		status.Condition = "Update verfügbar"
		status.Suggestion = "Aktualisieren"
	case mismatches > 0:
		status.Condition = "Dateien abweichend oder beschädigt"
		status.Suggestion = "Reparieren"
	default:
		status.Condition = "Aktuell und unverändert"
		status.Suggestion = "Keine Änderung nötig"
	}
	return status, nil
}

func companionDirectory() (string, error) {
	base := os.Getenv("LOCALAPPDATA")
	if base == "" {
		var err error
		base, err = os.UserConfigDir()
		if err != nil {
			return "", err
		}
	}
	return filepath.Join(base, "Banklord", "Companion"), nil
}

func inspectCompanion(payload []byte) (ComponentStatus, error) {
	dir, err := companionDirectory()
	if err != nil {
		return ComponentStatus{}, err
	}
	dest := filepath.Join(dir, "Banklord-Companion"+exeSuffix)
	status := ComponentStatus{Name: "Windows-Companion", Path: dest, PackagedVersion: bundledCompanionVersion, Condition: "Nicht installiert", Suggestion: "Installieren"}
	fi, err := os.Lstat(dest)
	if os.IsNotExist(err) {
		return status, nil
	}
	if err != nil {
		return status, err
	}
	status.Present = true
	if !fi.Mode().IsRegular() || fi.Mode()&os.ModeSymlink != 0 {
		status.Condition = "Unsichere Companion-Datei"
		status.Suggestion = "Nicht verändern"
		return status, nil
	}
	hash, err := fileHash(dest)
	if err != nil {
		return status, err
	}
	if hash == hashBytes(payload) {
		status.Recognized = true
		status.InstalledVersion = bundledCompanionVersion
		status.Condition = "Aktuell und unverändert"
		status.Suggestion = "Keine Änderung nötig"
		return status, nil
	}
	manifest, err := os.ReadFile(filepath.Join(dir, "install-manifest.json"))
	if err == nil {
		var m componentManifest
		if json.Unmarshal(manifest, &m) == nil && m.SHA256 == hash && m.Version != "" {
			status.Recognized = true
			status.InstalledVersion = m.Version
			cmp, ok := compareVersions(m.Version, bundledCompanionVersion)
			if ok && cmp > 0 {
				status.NewerInstalled = true
				status.Condition = "Installierte Version ist neuer"
				status.Suggestion = "Kein Downgrade"
				return status, nil
			}
			if ok && cmp < 0 {
				status.Condition = "Update verfügbar"
				status.Suggestion = "Aktualisieren"
				return status, nil
			}
			status.Condition = "Companion-Datei geändert"
			status.Modified = true
			status.Suggestion = "Reparieren"
			return status, nil
		}
	}
	status.Condition = "Bestehende Version nicht sicher bestimmbar"
	status.Suggestion = "Explizite Reparatur möglich (mit Sicherung)"
	return status, nil
}

func fileHash(path string) (string, error) {
	fd, err := os.Open(path)
	if err != nil {
		return "", err
	}
	defer fd.Close()
	sum := sha256.New()
	if _, err = io.Copy(sum, io.LimitReader(fd, 100<<20)); err != nil {
		return "", err
	}
	return hex.EncodeToString(sum.Sum(nil)), nil
}

func writeCompanionManifest(dir, version, sha string) error {
	blob, err := json.MarshalIndent(componentManifest{Version: version, SHA256: sha}, "", "  ")
	if err != nil {
		return err
	}
	tmp := filepath.Join(dir, "install-manifest.json.tmp")
	if err := os.WriteFile(tmp, blob, 0600); err != nil {
		return err
	}
	if err := os.Rename(tmp, filepath.Join(dir, "install-manifest.json")); err != nil {
		os.Remove(tmp)
		return err
	}
	return nil
}

func timestamp() string { return time.Now().Format("20060102-150405.000000000") }

// An uninstall retains WoW saved variables, character settings and all other addons.
// Addon source files are quarantined *outside* Interface/AddOns, for recoverability.
func uninstallAddon(client string) (string, error) {
	client = normalizeInput(client)
	if _, ok := candidateInstall(client); !ok {
		return "", errors.New("kein gültiger WoW-Client")
	}
	path := filepath.Join(client, "Interface", "AddOns", addonDirectory)
	fi, err := os.Lstat(path)
	if os.IsNotExist(err) {
		return "", nil
	}
	if err != nil {
		return "", err
	}
	if !fi.IsDir() || fi.Mode()&os.ModeSymlink != 0 {
		return "", errors.New("AddOn-Ziel ist kein normaler Ordner")
	}
	toc, err := os.ReadFile(filepath.Join(path, "Banklord.toc"))
	if err != nil || !strings.Contains(strings.ToLower(string(toc)), "## title: banklord") {
		return "", errors.New("fremder Ordner – nicht entfernt")
	}
	archiveDir := filepath.Join(client, "Banklord-Entfernungsarchiv")
	if err := os.MkdirAll(archiveDir, 0700); err != nil {
		return "", err
	}
	saved := filepath.Join(archiveDir, "Banklord-"+timestamp())
	if err := os.Rename(path, saved); err != nil {
		return "", err
	}
	return saved, nil
}

func removeClientPath(client string) error {
	config, err := os.UserConfigDir()
	if err != nil {
		return err
	}
	dest := filepath.Join(config, "Banklord", "known_clients.json")
	b, err := os.ReadFile(dest)
	if os.IsNotExist(err) {
		return nil
	}
	if err != nil {
		return err
	}
	var paths []string
	if err = json.Unmarshal(b, &paths); err != nil {
		return err
	}
	remain := make([]string, 0, len(paths))
	for _, p := range paths {
		if !strings.EqualFold(filepath.Clean(p), filepath.Clean(client)) {
			remain = append(remain, p)
		}
	}
	payload, err := json.MarshalIndent(remain, "", "  ")
	if err != nil {
		return err
	}
	tmp := dest + ".tmp"
	if err = os.WriteFile(tmp, payload, 0600); err != nil {
		return err
	}
	return os.Rename(tmp, dest)
}

func uninstallCompanion() (string, error) {
	if running, why := companionIsRunning(); running {
		return "", fmt.Errorf("Companion läuft noch (%s); bitte vor der Deinstallation beenden", why)
	}
	dir, err := companionDirectory()
	if err != nil {
		return "", err
	}
	target := filepath.Join(dir, "Banklord-Companion"+exeSuffix)
	info, err := os.Lstat(target)
	if os.IsNotExist(err) {
		_ = removeAutostart()
		_ = removeCompanionShortcuts(target)
		return "", nil
	}
	if err != nil {
		return "", err
	}
	if !info.Mode().IsRegular() || info.Mode()&os.ModeSymlink != 0 {
		return "", errors.New("unsicherer Companion-Pfad")
	}
	// Do not delete authentication/configuration files. Save the executable for rollback.
	backupDir := filepath.Join(dir, "removed")
	if err = os.MkdirAll(backupDir, 0700); err != nil {
		return "", err
	}
	backup := filepath.Join(backupDir, "Banklord-Companion-"+timestamp()+".exe")
	if err = os.Rename(target, backup); err != nil {
		return "", fmt.Errorf("Companion läuft möglicherweise noch; bitte beenden: %w", err)
	}
	_ = os.Remove(filepath.Join(dir, "install-manifest.json"))
	if err = removeCompanionShortcuts(target); err != nil {
		return backup, fmt.Errorf("Companion entfernt, Verknüpfungen konnten nicht entfernt werden: %w", err)
	}
	if err = removeAutostart(); err != nil {
		return backup, fmt.Errorf("Companion entfernt, Autostart konnte nicht entfernt werden: %w", err)
	}
	return backup, nil
}
