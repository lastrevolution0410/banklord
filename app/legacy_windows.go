//go:build windows

package main

import (
	"log"
	"os"
	"path/filepath"
	"regexp"
	"strings"
)

// legacyRunLine matches one value line of "reg query <Run key>".
var legacyRunLine = regexp.MustCompile(`^\s+(\S.*?)\s+REG_(?:EXPAND_)?SZ\s+(.*)$`)

// retireLegacyInstall removes leftovers of the pre-1.0 "WoWGuildBank" tools:
// their autostart entries, running processes, program folders and startup
// links. Old builds opened a console window and a browser tab at every login.
// Profiles and logs under %LOCALAPPDATA%\WoWGuildBank are kept.
func retireLegacyInstall() {
	key := `HKCU\Software\Microsoft\Windows\CurrentVersion\Run`
	out, err := command("reg.exe", "query", key).Output()
	if err == nil {
		for _, line := range strings.Split(string(out), "\n") {
			m := legacyRunLine.FindStringSubmatch(strings.TrimRight(line, "\r"))
			if m == nil || !strings.Contains(strings.ToLower(m[1]+m[2]), "wowguildbank") {
				continue
			}
			if command("reg.exe", "delete", key, "/v", m[1], "/f").Run() == nil {
				log.Println("Alter Autostart-Eintrag entfernt:", m[1])
			}
		}
	}
	for _, exe := range []string{"WoWGuildBank-Companion.exe", "WoWGuildBank-Updater.exe", "WoWGuildBankCompanion.exe"} {
		_ = command("taskkill.exe", "/F", "/IM", exe).Run()
	}
	base := os.Getenv("LOCALAPPDATA")
	if base != "" {
		for _, dir := range []string{"Companion", "Updater"} {
			_ = os.RemoveAll(filepath.Join(base, "WoWGuildBank", dir))
		}
	}
	script := `$sh=New-Object -ComObject WScript.Shell; ` +
		`foreach($f in @($sh.SpecialFolders('Startup'),$sh.SpecialFolders('Desktop'),$sh.SpecialFolders('Programs'))){ ` +
		`Get-ChildItem -LiteralPath $f -Filter 'WoWGuildBank*' -File -ErrorAction SilentlyContinue | Remove-Item -Force -ErrorAction SilentlyContinue }`
	_ = command("powershell.exe", "-NoProfile", "-NonInteractive", "-Command", script).Run()
}

// quietCompanionAutostart rewrites an older companion autostart entry so the
// companion starts in the background at login instead of opening a browser tab.
func quietCompanionAutostart() {
	key := `HKCU\Software\Microsoft\Windows\CurrentVersion\Run`
	out, err := command("reg.exe", "query", key, "/v", "BanklordCompanion").Output()
	if err != nil || strings.Contains(string(out), autostartFlag) {
		return
	}
	dir, err := companionDirectory()
	if err != nil {
		return
	}
	if err = setAutostart(filepath.Join(dir, "Banklord-Companion.exe")); err != nil {
		log.Println("Companion-Autostart:", err)
	}
}
