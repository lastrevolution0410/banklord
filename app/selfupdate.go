package main

import (
	"runtime"
	"context"
	"log"
	"os"
	"os/exec"
	"path/filepath"
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
	if runtime.GOOS != "windows" || release.Updater.Version == "" || !runningAsInstalledUpdater() {
		return ""
	}
	if cmp, ok := compareVersions(release.Updater.Version, setupVersion); !ok || cmp <= 0 {
		return ""
	}
	data, err := fetchUpdateFile(ctx, updateHTTPClient(), release.Updater, 64<<20)
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
		err = tmp.Close()
	} else {
		tmp.Close()
	}
	if err != nil {
		return "Updater-Update: " + err.Error()
	}
	old := dest + ".old"
	_ = os.Remove(old)
	if err = os.Rename(dest, old); err != nil {
		return "Updater-Update: " + err.Error()
	}
	if err = os.Rename(tmp.Name(), dest); err != nil {
		_ = os.Rename(old, dest)
		return "Updater-Update: " + err.Error()
	}
	if err = exec.Command(dest, "--watch-updates").Start(); err != nil {
		_ = os.Remove(dest)
		_ = os.Rename(old, dest)
		return "Updater-Update: Neustart fehlgeschlagen: " + err.Error()
	}
	log.Println("Updater auf", release.Updater.Version, "aktualisiert; übergebe an neuen Prozess.")
	os.Exit(0)
	return ""
}

func cleanupOldUpdater() {
	// Lets the companion report the updater version in its heartbeat.
	_ = os.WriteFile(filepath.Join(filepath.Dir(updaterPath()), "version.txt"), []byte(setupVersion), 0o644)
	time.AfterFunc(30*time.Second, func() { _ = os.Remove(updaterPath() + ".old") })
}

// startBackgroundUpdater launches the installed updater right after setup.
func startBackgroundUpdater() {
	if _, err := os.Stat(updaterPath()); err == nil {
		_ = exec.Command(updaterPath(), "--watch-updates").Start()
	}
}
