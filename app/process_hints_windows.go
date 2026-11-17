//go:build windows

package main

import (
	"context"
	"os/exec"
	"path/filepath"
	"strings"
	"syscall"
	"time"
)

// If WoW is already running, its real executable path is a high-confidence
// discovery hint even when the installation is deeply nested on another drive.
// This is a Windows process metadata query only; it does not read game memory.
func runningClientHints() []string {
	ctx, cancel := context.WithTimeout(context.Background(), 4*time.Second)
	defer cancel()
	const script = `[Console]::OutputEncoding = New-Object System.Text.UTF8Encoding($false); Get-CimInstance Win32_Process -Filter "Name LIKE 'Wow%.exe'" | Where-Object { $_.ExecutablePath } | ForEach-Object { $_.ExecutablePath }`
	cmd := exec.CommandContext(ctx, "powershell.exe", "-NoProfile", "-NonInteractive", "-Command", script)
	cmd.SysProcAttr = &syscall.SysProcAttr{HideWindow: true}
	output, err := cmd.Output()
	if err != nil {
		return nil
	}
	var hints []string
	for _, line := range strings.Split(string(output), "\n") {
		p := strings.TrimSpace(line)
		if wowExeRE.MatchString(filepath.Base(p)) {
			hints = append(hints, filepath.Dir(p))
		}
	}
	return hints
}
