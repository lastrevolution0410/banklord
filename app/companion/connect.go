package companion

import (
	"context"
	"encoding/base64"
	"encoding/json"
	"errors"
	"net/http"
	"os"
	"path/filepath"
	"regexp"
	"strings"
)

// Verbindungscode from the website: "BL1C:" + base64url(JSON{s,g,t}).
type connectCode struct {
	Server string `json:"s"`
	Guild  int64  `json:"g"`
	Token  string `json:"t"`
}

func parseConnectCode(code string) (connectCode, error) {
	code = strings.TrimSpace(code)
	if !strings.HasPrefix(code, "BL1C:") || len(code) > 600 {
		return connectCode{}, errors.New("Verbindungscode ungültig")
	}
	raw, err := base64.RawURLEncoding.DecodeString(strings.TrimRight(strings.TrimPrefix(code, "BL1C:"), "="))
	if err != nil {
		return connectCode{}, errors.New("Verbindungscode beschädigt")
	}
	var c connectCode
	if json.Unmarshal(raw, &c) != nil || !tokenPattern.MatchString(c.Token) || c.Guild < 1 {
		return connectCode{}, errors.New("Verbindungscode unvollständig")
	}
	c.Server = strings.TrimRight(c.Server, "/")
	return c, nil
}

var tocVersion = regexp.MustCompile(`(?m)^## Version: *(\S+)`)

// heartbeat reports installed versions so the admin can see outdated PCs.
func heartbeat(ctx context.Context, p Profile, token string) {
	host, _ := os.Hostname()
	if len(host) > 60 {
		host = host[:60]
	}
	addon := ""
	if dir, err := addonDir(p.Source); err == nil {
		if b, err := os.ReadFile(filepath.Join(dir, "Banklord.toc")); err == nil {
			if m := tocVersion.FindSubmatch(b); m != nil {
				addon = string(m[1])
			}
		}
	}
	updater := ""
	if b, err := os.ReadFile(filepath.Join(filepath.Dir(updaterExePath()), "version.txt")); err == nil {
		updater = strings.TrimSpace(string(b))
	}
	body, _ := json.Marshal(map[string]string{"device": host, "companion": companionVersion, "addon": addon, "updater": updater})
	_, _, _ = requestJSON(ctx, p, token, http.MethodPost, "/heartbeat", body)
}

func updaterExePath() string {
	base := os.Getenv("LOCALAPPDATA")
	if base == "" {
		base, _ = os.UserConfigDir()
	}
	return filepath.Join(base, "Banklord", "Updater", "Banklord-Updater.exe")
}
