//go:build windows

package main

import (
	"fmt"
	"os/exec"
)

func setAutostart(path string) error {
	out, err := exec.Command("reg.exe", "add", `HKCU\Software\Microsoft\Windows\CurrentVersion\Run`, "/v", "BanklordCompanion", "/t", "REG_SZ", "/d", fmt.Sprintf(`"%s"`, path), "/f").CombinedOutput()
	if err != nil {
		return fmt.Errorf("%w: %s", err, string(out))
	}
	return nil
}
func launchCompanion(path string) error { return exec.Command(path).Start() }

// stopCompanion ends a running (older) companion so the new one can start.
func stopCompanion() { _ = exec.Command("taskkill.exe", "/F", "/IM", "Banklord-Companion.exe").Run() }
