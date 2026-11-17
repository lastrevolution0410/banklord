//go:build windows

package main

import (
	"fmt"
	"os"
	"os/exec"
	"path/filepath"
	"syscall"
	"unsafe"
)

// A Windows shortcut is a convenience, not a hook into Battle.net. Users must
// explicitly launch via this shortcut to enforce a live preflight check.
func installCheckedLaunchShortcut(updater string) error {
	script := `$sh = New-Object -ComObject WScript.Shell; ` +
		`$link = $sh.CreateShortcut((Join-Path $sh.SpecialFolders('Desktop') 'Banklord - sicher starten.lnk')); ` +
		`$link.TargetPath = $env:BANKLORD_UPDATE_EXE; $link.Arguments = '--preflight-launch'; ` +
		`$link.WorkingDirectory = (Split-Path -Parent $env:BANKLORD_UPDATE_EXE); $link.Save()`
	cmd := exec.Command("powershell.exe", "-NoProfile", "-NonInteractive", "-Command", script)
	cmd.Env = append(os.Environ(), "BANKLORD_UPDATE_EXE="+filepath.Clean(updater))
	return cmd.Run()
}

func showPreflightFailure(message string) {
	lib := syscall.NewLazyDLL("user32.dll")
	msg := lib.NewProc("MessageBoxW")
	text, _ := syscall.UTF16PtrFromString(message)
	title, _ := syscall.UTF16PtrFromString("Banklord: Updateprüfung")
	msg.Call(0, uintptr(unsafe.Pointer(text)), uintptr(unsafe.Pointer(title)), 0x10)
}

// The current user can launch the separately installed Companion without
// opening the web installer or knowing the AppData directory.
func installCompanionShortcuts(target string) error {
	script := `$ErrorActionPreference='Stop'; ` +
		`$sh=New-Object -ComObject WScript.Shell; ` +
		`$desktop=[Environment]::GetFolderPath('Desktop'); ` +
		`$programs=[Environment]::GetFolderPath('Programs'); ` +
		`foreach($folder in @($desktop,$programs)) { ` +
		`if(-not [string]::IsNullOrWhiteSpace($folder)) { ` +
		`$lnk=$sh.CreateShortcut((Join-Path $folder 'Banklord Companion.lnk')); ` +
		`$lnk.TargetPath=$env:BANKLORD_COMPANION_EXE; ` +
		`$lnk.WorkingDirectory=(Split-Path -Parent $env:BANKLORD_COMPANION_EXE); ` +
		`$lnk.Description='Banklord Companion - lokale Synchronisierung'; ` +
		`$lnk.Save() } }`
	cmd := exec.Command("powershell.exe", "-NoProfile", "-NonInteractive", "-Command", script)
	cmd.Env = append(os.Environ(), "BANKLORD_COMPANION_EXE="+filepath.Clean(target))
	if out, err := cmd.CombinedOutput(); err != nil {
		return fmt.Errorf("Windows-Companion-Verknüpfungen: %v: %s", err, string(out))
	}
	return nil
}

// Remove ONLY shortcuts still pointing to our managed executable. Never
// delete a renamed or user-retargeted shortcut.
func removeCompanionShortcuts(target string) error {
	script := `$ErrorActionPreference='Stop'; ` +
		`$sh=New-Object -ComObject WScript.Shell; ` +
		`$desktop=[Environment]::GetFolderPath('Desktop'); ` +
		`$programs=[Environment]::GetFolderPath('Programs'); ` +
		`foreach($folder in @($desktop,$programs)) { ` +
		`if(-not [string]::IsNullOrWhiteSpace($folder)) { ` +
		`$path=Join-Path $folder 'Banklord Companion.lnk'; ` +
		`if(Test-Path -LiteralPath $path) { ` +
		`$lnk=$sh.CreateShortcut($path); ` +
		`if([string]::Equals($lnk.TargetPath,$env:BANKLORD_COMPANION_EXE,[StringComparison]::OrdinalIgnoreCase)) { Remove-Item -LiteralPath $path -Force } ` +
		`} } }`
	cmd := exec.Command("powershell.exe", "-NoProfile", "-NonInteractive", "-Command", script)
	cmd.Env = append(os.Environ(), "BANKLORD_COMPANION_EXE="+filepath.Clean(target))
	if out, err := cmd.CombinedOutput(); err != nil {
		return fmt.Errorf("Windows-Companion-Verknüpfungen entfernen: %v: %s", err, string(out))
	}
	return nil
}
