//go:build !windows

package companion

// Token storage on macOS (login keychain) and Linux (Secret Service via
// secret-tool). Without a Secret Service the token falls back to the profile
// file, which is written with mode 0600. The token never appears on a command line.

import (
	"bytes"
	"encoding/base64"
	"errors"
	"os/exec"
	"runtime"
	"strings"
)

const (
	keyService = "Banklord Companion"
	keyAccount = "bridge-token"
	markMac    = "keychain:v1"
	markLinux  = "secret-service:v1"
	markFile   = "file:v1:"
)

func protectToken(s string) (string, error) { return protectNamed(keyAccount, s) }

// protectNamed stores a secret under its own keychain entry; the returned mark
// names that entry (the primary bridge token keeps the original mark).
func protectNamed(account, s string) (string, error) {
	suffix := ""
	if account != keyAccount {
		suffix = ":" + account
	}
	switch runtime.GOOS {
	case "darwin":
		// "security -i" reads the command from stdin, keeping the token out of ps.
		cmd := exec.Command("security", "-i")
		cmd.Stdin = strings.NewReader(`add-generic-password -U -a "` + account + `" -s "` + keyService + `" -w "` + s + "\"\n")
		if out, err := cmd.CombinedOutput(); err != nil || bytes.Contains(out, []byte("rror")) {
			return "", errors.New("Schlüsselbund nicht verfügbar")
		}
		return markMac + suffix, nil
	default:
		if path, err := exec.LookPath("secret-tool"); err == nil {
			cmd := exec.Command(path, "store", "--label="+keyService, "service", keyService, "account", account)
			cmd.Stdin = strings.NewReader(s)
			if cmd.Run() == nil {
				return markLinux + suffix, nil
			}
		}
		return markFile + base64.StdEncoding.EncodeToString([]byte(s)), nil
	}
}

func unprotectToken(s string) (string, error) {
	var out []byte
	var err error
	account := keyAccount
	if i := strings.Index(s, ":v1:"); i > 0 && !strings.HasPrefix(s, markFile) {
		account, s = s[i+4:], s[:i+3]
	}
	switch {
	case s == markMac:
		out, err = exec.Command("security", "find-generic-password", "-a", account, "-s", keyService, "-w").Output()
	case s == markLinux:
		out, err = exec.Command("secret-tool", "lookup", "service", keyService, "account", account).Output()
	case strings.HasPrefix(s, markFile):
		out, err = base64.StdEncoding.DecodeString(strings.TrimPrefix(s, markFile))
	default:
		return "", errors.New("Schlüssel stammt von einem anderen System; bitte Verbindungscode neu eingeben")
	}
	if err != nil {
		return "", errors.New("gespeicherter Schlüssel nicht lesbar; bitte Verbindungscode neu eingeben")
	}
	return strings.TrimRight(string(out), "\r\n"), nil
}
