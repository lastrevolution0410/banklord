//go:build !windows

package main

// macOS and Linux integration for the single app binary:
//   - companion:  <app> --companion        (local browser UI, uploads)
//   - updater:    <app> --watch-updates    (signed updates, background)
// Autostart uses a LaunchAgent on macOS and an XDG autostart entry on Linux.

import (
	"errors"
	"fmt"
	"os"
	"os/exec"
	"path/filepath"
	"runtime"
	"strings"
	"syscall"
)

const (
	companionAgent = "net.banklord.companion"
	updaterAgent   = "net.banklord.updater"
)

func platformRoots() []string {
	home, _ := os.UserHomeDir()
	roots := []string{}
	if runtime.GOOS == "darwin" {
		roots = append(roots, "/Applications/World of Warcraft", filepath.Join(home, "Applications", "World of Warcraft"), "/Applications/Games/World of Warcraft")
	}
	pf := []string{"drive_c/Program Files (x86)/World of Warcraft", "drive_c/Program Files/World of Warcraft"}
	prefixes := []string{filepath.Join(home, ".wine"), filepath.Join(home, "Games", "world-of-warcraft"), filepath.Join(home, "Games", "battlenet")}
	for _, pattern := range []string{
		filepath.Join(home, "Games", "*"),
		filepath.Join(home, ".local/share/Steam/steamapps/compatdata/*/pfx"),
		filepath.Join(home, ".steam/steam/steamapps/compatdata/*/pfx"),
		filepath.Join(home, ".var/app/com.valvesoftware.Steam/data/Steam/steamapps/compatdata/*/pfx"),
		filepath.Join(home, ".var/app/com.usebottles.bottles/data/bottles/bottles/*"),
		filepath.Join(home, ".local/share/bottles/bottles/*"),
	} {
		if m, err := filepath.Glob(pattern); err == nil {
			prefixes = append(prefixes, m...)
		}
	}
	for _, p := range prefixes {
		for _, sub := range pf {
			roots = append(roots, filepath.Join(p, sub))
		}
	}
	roots = append(roots, filepath.Join(home, "Games", "World of Warcraft"))
	return roots
}

func launchDetached(path string, args ...string) error {
	cmd := exec.Command(path, args...)
	cmd.SysProcAttr = &syscall.SysProcAttr{Setsid: true}
	return cmd.Start()
}

func launchCompanion(path string) error { return launchDetached(path, "--companion") }

func xmlEscape(s string) string {
	return strings.NewReplacer("&", "&amp;", "<", "&lt;", ">", "&gt;", `"`, "&quot;").Replace(s)
}

// desktopQuote quotes an argument for the Exec key of a .desktop file.
func desktopQuote(s string) string {
	return `"` + strings.NewReplacer(`\`, `\\`, `"`, `\"`, "`", "\\`", "$", `\$`).Replace(s) + `"`
}

func autostartFile(label string) (string, error) {
	home, err := os.UserHomeDir()
	if err != nil {
		return "", err
	}
	if runtime.GOOS == "darwin" {
		return filepath.Join(home, "Library", "LaunchAgents", label+".plist"), nil
	}
	cfg, err := os.UserConfigDir()
	if err != nil {
		return "", err
	}
	return filepath.Join(cfg, "autostart", label+".desktop"), nil
}

func writeAutostart(label, name, path, arg string) error {
	file, err := autostartFile(label)
	if err != nil {
		return err
	}
	if err = os.MkdirAll(filepath.Dir(file), 0o755); err != nil {
		return err
	}
	var body string
	if runtime.GOOS == "darwin" {
		body = fmt.Sprintf(`<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0"><dict>
<key>Label</key><string>%s</string>
<key>ProgramArguments</key><array><string>%s</string><string>%s</string></array>
<key>RunAtLoad</key><true/>
<key>ProcessType</key><string>Background</string>
</dict></plist>
`, label, xmlEscape(path), arg)
	} else {
		body = fmt.Sprintf("[Desktop Entry]\nType=Application\nName=%s\nExec=%s %s\nX-GNOME-Autostart-enabled=true\nNoDisplay=true\n", name, desktopQuote(path), arg)
	}
	return os.WriteFile(file, []byte(body), 0o644)
}

func deleteAutostart(label string) error {
	file, err := autostartFile(label)
	if err != nil {
		return err
	}
	if err = os.Remove(file); err != nil && !errors.Is(err, os.ErrNotExist) {
		return err
	}
	return nil
}

func setAutostart(path string) error {
	return writeAutostart(companionAgent, "Banklord Companion", path, "--companion")
}

func removeAutostart() error { return deleteAutostart(companionAgent) }

// configureUpdaterAutostart copies this binary to the updater location and
// registers it to run with --watch-updates at login.
func configureUpdaterAutostart(enabled bool) error {
	if !enabled {
		return deleteAutostart(updaterAgent)
	}
	me, err := os.Executable()
	if err != nil {
		return err
	}
	dest := updaterPath()
	if err = os.MkdirAll(filepath.Dir(dest), 0o700); err != nil {
		return err
	}
	if filepath.Clean(me) != filepath.Clean(dest) {
		data, err := os.ReadFile(me)
		if err != nil {
			return err
		}
		tmp := dest + ".tmp"
		if err = os.WriteFile(tmp, data, 0o700); err != nil {
			return err
		}
		if err = os.Rename(tmp, dest); err != nil {
			os.Remove(tmp)
			return err
		}
	}
	return writeAutostart(updaterAgent, "Banklord Updates", dest, "--watch-updates")
}

// pgrep reports whether a process command line matches the pattern.
func pgrep(pattern string) (bool, error) {
	err := exec.Command("pgrep", "-if", pattern).Run()
	if err == nil {
		return true, nil
	}
	var exit *exec.ExitError
	if errors.As(err, &exit) && exit.ExitCode() == 1 {
		return false, nil
	}
	return false, err
}

const wowProcessPattern = `World of Warcraft[^/]*\.app/Contents/MacOS|Wow(Classic)?(T|B|-64)?\.exe`

func wowIsRunning() (bool, string) {
	if ok, _ := pgrep(wowProcessPattern); ok {
		return true, "WoW-Prozess gefunden"
	}
	return false, ""
}

func wowRunningChecked() (bool, error) { return pgrep(wowProcessPattern) }

func companionIsRunning() (bool, string) {
	if ok, _ := pgrep(`Banklord-Companion --companion`); ok {
		return true, "Companion-Prozess gefunden"
	}
	return false, ""
}

func runningClientHints() []string { return nil }

// stopCompanion ends a running (older) companion so the new one can start.
func stopCompanion() { _ = exec.Command("pkill", "-f", "Banklord-Companion --companion").Run() }
