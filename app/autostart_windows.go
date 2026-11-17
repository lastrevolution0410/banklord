//go:build windows

package main

import (
	"fmt"
	"os/exec"
)

func removeAutostart() error {
	key := `HKCU\Software\Microsoft\Windows\CurrentVersion\Run`
	args := []string{"/v", "BanklordCompanion"}
	out, err := exec.Command("reg.exe", "delete", key, args[0], args[1], "/f").CombinedOutput()
	if err == nil {
		return nil
	}
	// Distinguish an already absent value from an actual deletion error.
	if _, qErr := exec.Command("reg.exe", "query", key, args[0], args[1]).CombinedOutput(); qErr != nil {
		return nil
	}
	return fmt.Errorf("Autostart-Eintrag nicht entfernt: %s: %w", string(out), err)
}
