package main

import (
	"archive/zip"
	"bytes"
	"context"
	"errors"
	"fmt"
	"io"
	"log"
	"os"
	"path/filepath"
	"runtime"
	"strings"
	"time"
)

// updaterPath is where configureUpdaterAutostart installs the background updater.
func updaterPath() string {
	base := os.Getenv("LOCALAPPDATA")
	if base == "" {
		base, _ = os.UserConfigDir()
	}
	return filepath.Join(base, "Banklord", "Updater", "Banklord-Updater"+exeSuffix)
}

func runningAsInstalledUpdater() bool {
	me, err := os.Executable()
	if err != nil || len(os.Args) < 2 || os.Args[1] != "--watch-updates" {
		return false
	}
	return strings.EqualFold(filepath.Clean(me), filepath.Clean(updaterPath()))
}

// selfUpdateIfNewer replaces the running background updater with a newer signed
// build: rename the running file aside (allowed on Windows), move the verified
// new file in place, start it and exit. The new process waits for the watcher lock.
func selfUpdateIfNewer(ctx context.Context, release releasePayload) string {
	if !runningAsInstalledUpdater() {
		return ""
	}
	own := release.Updater
	if runtime.GOOS != "windows" {
		own = release.Apps[runtime.GOOS+"-"+runtime.GOARCH]
	}
	if own.Version == "" {
		return ""
	}
	if cmp, ok := compareVersions(own.Version, setupVersion); !ok || cmp <= 0 {
		return ""
	}
	data, err := fetchUpdateFile(ctx, updateHTTPClient(), own, 64<<20)
	if err == nil && runtime.GOOS != "windows" {
		data, err = binaryFromAppZip(data)
	}
	if err != nil {
		return "Updater-Update: " + err.Error()
	}
	dest := updaterPath()
	dir := filepath.Dir(dest)
	tmp, err := os.CreateTemp(dir, ".updater-*.tmp")
	if err != nil {
		return "Updater-Update: " + err.Error()
	}
	defer os.Remove(tmp.Name())
	if _, err = tmp.Write(data); err == nil {
		err = tmp.Chmod(0o700)
	}
	if err == nil {
		err = tmp.Close()
	} else {
		tmp.Close()
	}
	if err != nil {
		return "Updater-Update: " + err.Error()
	}
	old, err := replaceRunningFile(tmp.Name(), dest)
	if err != nil {
		return "Updater-Update: " + err.Error()
	}
	if err = command(dest, "--watch-updates").Start(); err != nil {
		if old != "" {
			_ = os.Remove(dest)
			_ = os.Rename(old, dest)
		}
		return "Updater-Update: Neustart fehlgeschlagen: " + err.Error()
	}
	log.Println("Updater auf", own.Version, "aktualisiert; übergebe an neuen Prozess.")
	os.Exit(0)
	return ""
}

func cleanupOldUpdater() {
	// Lets the companion report the updater version in its heartbeat.
	_ = os.WriteFile(filepath.Join(filepath.Dir(updaterPath()), "version.txt"), []byte(setupVersion), 0o644)
	time.AfterFunc(30*time.Second, func() { removeOldCopies(updaterPath()) })
}

// replaceRunningFile moves src over dest. Windows refuses to overwrite a
// running .exe but allows renaming it, so the current file is first moved to
// a unique "<dest>.old-<n>" name. Returns that name ("" if none was needed).
func replaceRunningFile(src, dest string) (string, error) {
	removeOldCopies(dest)
	if err := os.Rename(src, dest); err == nil {
		return "", nil
	}
	old := fmt.Sprintf("%s.old-%d", dest, time.Now().UnixNano())
	if err := os.Rename(dest, old); err != nil && !os.IsNotExist(err) {
		return "", err
	}
	if err := os.Rename(src, dest); err != nil {
		_ = os.Rename(old, dest)
		return "", err
	}
	return old, nil
}

// removeOldCopies deletes leftovers from replaceRunningFile; files still in
// use stay and are retried next time.
func removeOldCopies(dest string) {
	matches, _ := filepath.Glob(dest + ".old*")
	for _, m := range matches {
		_ = os.Remove(m)
	}
}

// startBackgroundUpdater launches the installed updater right after setup.
func startBackgroundUpdater() {
	if _, err := os.Stat(updaterPath()); err == nil {
		_ = command(updaterPath(), "--watch-updates").Start()
	}
}

// binaryFromAppZip returns the program inside a macOS/Linux release zip:
// "Banklord.app/Contents/MacOS/Banklord" on macOS, "banklord" on Linux.
func binaryFromAppZip(b []byte) ([]byte, error) {
	r, err := zip.NewReader(bytes.NewReader(b), int64(len(b)))
	if err != nil {
		return nil, err
	}
	for _, f := range r.File {
		n := strings.ReplaceAll(f.Name, "\\", "/")
		if n != "Banklord.app/Contents/MacOS/Banklord" && n != "banklord" {
			continue
		}
		if f.UncompressedSize64 > 64<<20 {
			return nil, errors.New("Programm im Paket zu groß")
		}
		rc, err := f.Open()
		if err != nil {
			return nil, err
		}
		defer rc.Close()
		return io.ReadAll(io.LimitReader(rc, 64<<20))
	}
	return nil, errors.New("Programm im Paket nicht gefunden")
}

// syncUnixCompanion refreshes the installed companion copy on macOS/Linux,
// where the companion is the same program, once it is not running.
func syncUnixCompanion() string {
	if runtime.GOOS == "windows" || !runningAsInstalledUpdater() {
		return ""
	}
	payload, err := companionPayload()
	if err != nil {
		return ""
	}
	st, err := inspectCompanion(payload)
	if err != nil || !st.Present || st.Recognized {
		return ""
	}
	if running, _ := companionIsRunning(); running {
		return "Companion-Update wartet, bis der Companion beendet ist"
	}
	if _, err := installCompanionVersion(payload, setupVersion, false, false); err != nil {
		return "Companion-Update: " + err.Error()
	}
	return "Companion aktualisiert auf " + setupVersion
}
