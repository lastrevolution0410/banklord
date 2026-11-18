package main

import (
	"archive/zip"
	"bytes"
	"errors"
	"fmt"
	"io"
	"os"
	"path/filepath"
	"regexp"
	"strings"
	"time"
)

const addonDirectory = "Banklord"

type InstallOutcome struct {
	AddonPath     string
	BackupPath    string
	CompanionPath string
	Warning       string
}

func unpackAddon(archive []byte, out string) error {
	r, e := zip.NewReader(bytes.NewReader(archive), int64(len(archive)))
	if e != nil {
		return e
	}
	files := 0
	for _, f := range r.File {
		if f.FileInfo().Mode()&os.ModeSymlink != 0 {
			return errors.New("symbolische Links im Archiv sind nicht erlaubt")
		}
		if f.FileInfo().IsDir() {
			continue
		}
		name := strings.ReplaceAll(f.Name, "\\", "/")
		if !strings.HasPrefix(name, "Banklord/") || strings.Contains(name, "../") || strings.HasPrefix(name, "/") {
			continue
		}
		rel := strings.TrimPrefix(name, "Banklord/")
		if strings.Contains(rel, "/") || !(strings.HasSuffix(strings.ToLower(rel), ".lua") || strings.HasSuffix(strings.ToLower(rel), ".toc") || rel == "GoldBars.tga") {
			continue
		}
		if f.UncompressedSize64 > 2*1024*1024 {
			return errors.New("AddOn-Datei ist zu groß")
		}
		reader, e := f.Open()
		if e != nil {
			return e
		}
		b, e := io.ReadAll(io.LimitReader(reader, 2*1024*1024+1))
		reader.Close()
		if e != nil {
			return e
		}
		if len(b) > 2*1024*1024 {
			return errors.New("Addon zu groß")
		}
		if e = os.WriteFile(filepath.Join(out, filepath.Base(rel)), b, 0644); e != nil {
			return e
		}
		files++
	}
	if files < 4 {
		return fmt.Errorf("Addon-Archiv unvollständig: %d Dateien", files)
	}
	if _, e = os.Stat(filepath.Join(out, "Banklord.toc")); e != nil {
		return errors.New("Addon-Manifest fehlt")
	}
	return nil
}

// Copies a known-compatible AddOn only. No game executables, other addons or WTF files touched.
func installAddon(clientPath string, archive []byte) (InstallOutcome, error) {
	clientPath = normalizeInput(clientPath)
	found, ok := candidateInstall(clientPath)
	if !ok {
		return InstallOutcome{}, errors.New("kein gültiger WoW-Client am gewählten Ort (Spiel-EXE und Data im Client- oder Hauptordner benötigt)")
	}
	if !found.Supported {
		return InstallOutcome{}, errors.New("für diese WoW-Version ist noch kein getesteter Addon-Build freigegeben")
	}
	addonRoot := filepath.Join(clientPath, "Interface", "AddOns")
	if e := os.MkdirAll(addonRoot, 0755); e != nil {
		return InstallOutcome{}, e
	}
	stage, e := os.MkdirTemp(addonRoot, ".bl-staging-")
	if e != nil {
		return InstallOutcome{}, e
	}
	defer os.RemoveAll(stage)
	if e = unpackAddon(archive, stage); e != nil {
		return InstallOutcome{}, e
	}
	pinInterface(filepath.Join(stage, "Banklord.toc"), interfaceNumber(clientVersion(clientPath)))
	target := filepath.Join(addonRoot, addonDirectory)
	result := InstallOutcome{AddonPath: target}
	// Never follow a link from the target folder into another location.
	if info, e := os.Lstat(target); e == nil {
		if info.Mode()&os.ModeSymlink != 0 {
			return InstallOutcome{}, errors.New("Zielverzeichnis ist ein Link; Installation abgebrochen")
		}
		backupDir := filepath.Join(clientPath, "Banklord-Sicherungen")
		if e = os.MkdirAll(backupDir, 0700); e != nil {
			return InstallOutcome{}, e
		}
		// The companion writes these files (bank manager role, open orders,
		// unlock offers). An update must keep them, or the addon treats a
		// manager as a member until the companion rewrites them.
		keepCompanionFiles(target, stage)
		backup := filepath.Join(backupDir, fmt.Sprintf("Banklord.backup-%s", time.Now().Format("20060102-150405.000000000")))
		if e = os.Rename(target, backup); e != nil {
			return InstallOutcome{}, fmt.Errorf("Altversion konnte nicht gesichert werden: %w", e)
		}
		result.BackupPath = backup
	} else if !os.IsNotExist(e) {
		return InstallOutcome{}, e
	}
	if e = os.Rename(stage, target); e != nil {
		if result.BackupPath != "" {
			_ = os.Rename(result.BackupPath, target)
		}
		return InstallOutcome{}, fmt.Errorf("Installation fehlgeschlagen; vorherige Version zurückgesetzt: %w", e)
	}
	return result, nil
}

// keepCompanionFiles copies ServerState.lua and Claim.lua from the installed
// addon into the new one (regular files up to 2 MB only; links are ignored).
func keepCompanionFiles(from, to string) {
	for _, name := range []string{"ServerState.lua", "Claim.lua"} {
		src := filepath.Join(from, name)
		info, err := os.Lstat(src)
		if err != nil || !info.Mode().IsRegular() || info.Size() > 2<<20 {
			continue
		}
		data, err := os.ReadFile(src)
		if err != nil {
			continue
		}
		_ = os.WriteFile(filepath.Join(to, name), data, 0644)
	}
}

// pinInterface writes the detected client's interface number into the TOC so
// the game never lists Banklord as "out of date". Unknown builds keep the
// multi-version list shipped in the package.
func pinInterface(toc, iface string) {
	if iface == "" {
		return
	}
	data, err := os.ReadFile(toc)
	if err != nil {
		return
	}
	out := tocInterfaceRE.ReplaceAll(data, []byte("## Interface: "+iface))
	_ = os.WriteFile(toc, out, 0644)
}

var tocInterfaceRE = regexp.MustCompile(`(?m)^## Interface:[^\r\n]*`)
