//go:build windows

package main

import (
	"log"
	"os"
	"os/exec"
	"path/filepath"
	"strings"
	"time"
)

const runKey = `HKCU\Software\Microsoft\Windows\CurrentVersion\Run`

func legacyBase() string {
	base := os.Getenv("LOCALAPPDATA")
	if base == "" {
		base, _ = os.UserConfigDir()
	}
	return filepath.Join(base, legacyName)
}

func legacyUpdaterPath() string {
	return filepath.Join(legacyBase(), "Updater", legacyName+"-Updater.exe")
}

func runValueExists(name string) bool {
	return exec.Command("reg.exe", "query", runKey, "/v", name).Run() == nil
}

func deleteRunValue(name string) {
	_ = exec.Command("reg.exe", "delete", runKey, "/v", name, "/f").Run()
}

// migrateLegacyShortcuts re-creates an existing "sicher starten" desktop link
// for the new updater and removes the old links. The paths are passed as
// environment variables, never spliced into the script.
func migrateLegacyShortcuts() {
	script := `$sh=New-Object -ComObject WScript.Shell; $desk=$sh.SpecialFolders('Desktop'); ` +
		`$old=Join-Path $desk 'WoWGuildBank - sicher starten.lnk'; ` +
		`if(Test-Path -LiteralPath $old){ $l=$sh.CreateShortcut((Join-Path $desk 'Banklord - sicher starten.lnk')); ` +
		`$l.TargetPath=$env:BL_UPDATER; $l.Arguments='--preflight-launch'; $l.WorkingDirectory=(Split-Path $env:BL_UPDATER); $l.Save(); ` +
		`Remove-Item -LiteralPath $old -Force }; ` +
		`foreach($f in @($desk,$sh.SpecialFolders('Programs'),$sh.SpecialFolders('Startup'))){ ` +
		`$p=Join-Path $f 'WoWGuildBank Companion.lnk'; if(Test-Path -LiteralPath $p){Remove-Item -LiteralPath $p -Force} }`
	cmd := exec.Command("powershell.exe", "-NoProfile", "-NonInteractive", "-Command", script)
	cmd.Env = append(os.Environ(), "BL_UPDATER="+updaterPath())
	_ = cmd.Run()
}

// migrateLegacyWindows moves a 0.9.x WoWGuildBank installation to Banklord.
// It returns true when this process handed over to the new updater and must exit.
func migrateLegacyWindows() bool {
	me, err := os.Executable()
	if err != nil {
		return false
	}
	if strings.EqualFold(filepath.Clean(me), filepath.Clean(legacyUpdaterPath())) {
		// The old updater replaced itself with this build. Install under the
		// new name, switch autostart and hand over.
		if err := configureUpdaterAutostart(true); err != nil {
			log.Println("Umzug Updater:", err)
			return false
		}
		deleteRunValue("WoWGuildBankAutoUpdater")
		startBackgroundUpdater()
		log.Println("Umzug: Updater läuft jetzt als Banklord")
		return true
	}
	// Running as the new updater: clean up what is left of the old one.
	time.AfterFunc(45*time.Second, func() { _ = os.RemoveAll(filepath.Dir(legacyUpdaterPath())) })
	oldCompanion := filepath.Join(legacyBase(), "Companion", legacyName+"-Companion.exe")
	if _, err := os.Stat(oldCompanion); err != nil {
		return false
	}
	// Install the new companion next to the old one (different name and folder),
	// carry over autostart, then retire the old one. If it still runs, it keeps
	// running until logoff and is removed on a later start.
	autostart := runValueExists("WoWGuildBankCompanion")
	st, err := inspectCompanion(nil)
	if err != nil {
		log.Println("Umzug Companion:", err)
		return false
	}
	if !st.Present {
		payload, err := companionPayload()
		if err == nil {
			_, err = installCompanion(payload, autostart, false)
		}
		if err != nil {
			log.Println("Umzug Companion:", err)
			return false
		}
		log.Println("Umzug: Companion als Banklord installiert")
	} else if autostart {
		if err := setAutostart(st.Path); err != nil {
			log.Println("Umzug Companion-Autostart:", err)
			return false
		}
	}
	if autostart {
		deleteRunValue("WoWGuildBankCompanion")
	}
	migrateLegacyShortcuts()
	if !legacyCompanionRunning() {
		_ = os.RemoveAll(filepath.Join(legacyBase(), "Companion"))
	}
	return false
}

func legacyCompanionRunning() bool {
	out, err := exec.Command("tasklist.exe", "/FI", "IMAGENAME eq "+legacyName+"-Companion.exe", "/FO", "CSV", "/NH").Output()
	return err == nil && strings.Contains(strings.ToLower(string(out)), strings.ToLower(legacyName+"-companion.exe"))
}
