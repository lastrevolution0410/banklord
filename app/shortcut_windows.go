//go:build windows

package main

import (
	"fmt"
	"os"
	"path/filepath"
	"syscall"
	"unsafe"
)

// removeCheckedLaunchShortcut deletes the old "Banklord - sicher starten"
// desktop shortcut, but only while it still points to our updater with the
// preflight argument; a shortcut the user changed is left alone.
func removeCheckedLaunchShortcut(updater string) {
	script := `$sh = New-Object -ComObject WScript.Shell; ` +
		`$path = Join-Path ([Environment]::GetFolderPath('Desktop')) 'Banklord - sicher starten.lnk'; ` +
		`if (Test-Path -LiteralPath $path) { $link = $sh.CreateShortcut($path); ` +
		`if ([string]::Equals($link.TargetPath,$env:BANKLORD_UPDATE_EXE,[StringComparison]::OrdinalIgnoreCase) -and $link.Arguments -eq '--preflight-launch') { Remove-Item -LiteralPath $path -Force } }`
	cmd := command("powershell.exe", "-NoProfile", "-NonInteractive", "-Command", script)
	cmd.Env = append(os.Environ(), "BANKLORD_UPDATE_EXE="+filepath.Clean(updater))
	_ = cmd.Run()
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
		`$lnk.IconLocation=$env:BANKLORD_ICON+',0'; ` +
		`$lnk.Save() } }`
	cmd := command("powershell.exe", "-NoProfile", "-NonInteractive", "-Command", script)
	cmd.Env = append(os.Environ(), "BANKLORD_COMPANION_EXE="+filepath.Clean(target), "BANKLORD_ICON="+iconSource(target))
	if out, err := cmd.CombinedOutput(); err != nil {
		return fmt.Errorf("Windows-Companion-Verknüpfungen: %v: %s", err, string(out))
	}
	return nil
}

// iconSource names a file that certainly carries the Banklord icon: the
// updater (always built with it), else the companion itself.
func iconSource(companion string) string {
	if u := updaterPath(); u != "" {
		if fi, err := os.Stat(u); err == nil && fi.Mode().IsRegular() {
			return filepath.Clean(u)
		}
	}
	return filepath.Clean(companion)
}

// refreshCompanionShortcutIcons gives EXISTING companion shortcuts (that still
// point to our executable) the Banklord icon; it never creates new ones.
func refreshCompanionShortcutIcons() {
	dir, err := companionDirectory()
	if err != nil {
		return
	}
	target := filepath.Join(dir, "Banklord-Companion.exe")
	script := `$sh=New-Object -ComObject WScript.Shell; ` +
		`foreach($folder in @([Environment]::GetFolderPath('Desktop'),[Environment]::GetFolderPath('Programs'))) { ` +
		`if(-not [string]::IsNullOrWhiteSpace($folder)) { ` +
		`$path=Join-Path $folder 'Banklord Companion.lnk'; ` +
		`if(Test-Path -LiteralPath $path) { $lnk=$sh.CreateShortcut($path); ` +
		`if([string]::Equals($lnk.TargetPath,$env:BANKLORD_COMPANION_EXE,[StringComparison]::OrdinalIgnoreCase) -and $lnk.IconLocation -ne ($env:BANKLORD_ICON+',0')) { ` +
		`$lnk.IconLocation=$env:BANKLORD_ICON+',0'; $lnk.Save() } } } }`
	cmd := command("powershell.exe", "-NoProfile", "-NonInteractive", "-Command", script)
	cmd.Env = append(os.Environ(), "BANKLORD_COMPANION_EXE="+target, "BANKLORD_ICON="+iconSource(target))
	_ = cmd.Run()
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
	cmd := command("powershell.exe", "-NoProfile", "-NonInteractive", "-Command", script)
	cmd.Env = append(os.Environ(), "BANKLORD_COMPANION_EXE="+filepath.Clean(target))
	if out, err := cmd.CombinedOutput(); err != nil {
		return fmt.Errorf("Windows-Companion-Verknüpfungen entfernen: %v: %s", err, string(out))
	}
	return nil
}
