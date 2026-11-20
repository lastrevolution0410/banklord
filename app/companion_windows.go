//go:build windows

package main

import (
	"fmt"
)

// autostartFlag tells the companion it was started at login, so it stays
// in the background instead of opening a browser tab.
const autostartFlag = "--autostart"

func setAutostart(path string) error {
	out, err := command("reg.exe", "add", `HKCU\Software\Microsoft\Windows\CurrentVersion\Run`, "/v", "BanklordCompanion", "/t", "REG_SZ", "/d", fmt.Sprintf(`"%s" %s`, path, autostartFlag), "/f").CombinedOutput()
	if err != nil {
		return fmt.Errorf("%w: %s", err, string(out))
	}
	return nil
}
func launchCompanion(path string) error { return command(path).Start() }

// stopCompanion ends a running (older) companion so the new one can start.
func stopCompanion() { _ = command("taskkill.exe", "/F", "/IM", "Banklord-Companion.exe").Run() }
