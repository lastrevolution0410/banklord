//go:build windows

package main

import (
	"fmt"
	"io"
	"os"
	"path/filepath"
	"strings"
)

func configureUpdaterAutostart(enabled bool) error {
	key := `HKCU\Software\Microsoft\Windows\CurrentVersion\Run`
	name := "BanklordAutoUpdater"
	if !enabled {
		err := command("reg.exe", "delete", key, "/v", name, "/f").Run()
		if err == nil {
			return nil
		}
		if command("reg.exe", "query", key, "/v", name).Run() != nil {
			return nil
		}
		return fmt.Errorf("Autostart konnte nicht entfernt werden: %w", err)
	}
	me, err := os.Executable()
	if err != nil {
		return err
	}
	base := os.Getenv("LOCALAPPDATA")
	if base == "" {
		base, err = os.UserConfigDir()
		if err != nil {
			return err
		}
	}
	dir := filepath.Join(base, "Banklord", "Updater")
	if err = os.MkdirAll(dir, 0700); err != nil {
		return err
	}
	dest := filepath.Join(dir, "Banklord-Updater.exe")
	if !strings.EqualFold(filepath.Clean(me), filepath.Clean(dest)) {
		src, err := os.Open(me)
		if err != nil {
			return err
		}
		defer src.Close()
		staged, err := os.CreateTemp(dir, ".updater-*.tmp")
		if err != nil {
			return err
		}
		temp := staged.Name()
		defer os.Remove(temp)
		if _, err = io.Copy(staged, src); err != nil {
			staged.Close()
			return err
		}
		if err = staged.Close(); err != nil {
			return err
		}
		if _, err = replaceRunningFile(temp, dest); err != nil {
			return err
		}
	}
	cmd := `"` + dest + `" --watch-updates`
	output, err := command("reg.exe", "add", key, "/v", name, "/t", "REG_SZ", "/d", cmd, "/f").CombinedOutput()
	if err != nil {
		return fmt.Errorf("Windows-Run-Eintrag: %s: %w", string(output), err)
	}
	// Failure to create a Desktop shortcut does NOT disable the independent
	// background updater; users can still use the installer's browser button.
	_ = installCheckedLaunchShortcut(dest)
	return nil
}
