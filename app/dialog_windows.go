//go:build windows

package main

import (
	"errors"
	"os/exec"
	"strings"
)

func selectFolder() (string, error) {
	script := `Add-Type -AssemblyName System.Windows.Forms; $f = New-Object System.Windows.Forms.FolderBrowserDialog; $f.Description = 'WoW-Installationsordner oder Interface\\AddOns auswählen'; $f.ShowNewFolderButton = $false; if ($f.ShowDialog() -eq [System.Windows.Forms.DialogResult]::OK) { [Console]::Write($f.SelectedPath) }`
	out, e := exec.Command("powershell.exe", "-NoProfile", "-STA", "-NonInteractive", "-Command", script).CombinedOutput()
	if e != nil {
		return "", e
	}
	result := strings.TrimSpace(string(out))
	if result == "" {
		return "", errors.New("keinen Ordner ausgewählt")
	}
	return result, nil
}
