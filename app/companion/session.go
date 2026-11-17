package companion

import (
	"encoding/json"
	"errors"
	"net/http"
	"net/url"
	"os"
	"path/filepath"
	"time"
)

var errOldCompanionRunning = errors.New("Alte Companion-Version läuft noch")

type browserSession struct {
	URL string `json:"url"`
}

func sessionPath() (string, error) {
	p, err := profilePath()
	if err != nil {
		return "", err
	}
	return filepath.Join(filepath.Dir(p), "session.json"), nil
}
func storeSession(u string) error {
	path, err := sessionPath()
	if err != nil {
		return err
	}
	if err := os.MkdirAll(filepath.Dir(path), 0700); err != nil {
		return err
	}
	b, err := json.Marshal(browserSession{u})
	if err != nil {
		return err
	}
	return os.WriteFile(path, b, 0600)
}
func removeSession() {
	if p, err := sessionPath(); err == nil {
		_ = os.Remove(p)
	}
}
func existingSession() (string, error) {
	path, err := sessionPath()
	if err != nil {
		return "", err
	}
	raw, err := os.ReadFile(path)
	if err != nil {
		return "", err
	}
	var s browserSession
	if err := json.Unmarshal(raw, &s); err != nil {
		return "", err
	}
	u, err := url.Parse(s.URL)
	if err != nil || u.Scheme != "http" || u.Hostname() != "127.0.0.1" || u.Port() == "" || u.User != nil || u.RawQuery != "" || u.Fragment != "" {
		return "", errors.New("Ungültiger lokaler Statusverweis")
	}
	cl := http.Client{Timeout: 2 * time.Second, CheckRedirect: func(req *http.Request, via []*http.Request) error { return http.ErrUseLastResponse }}
	resp, err := cl.Get(s.URL)
	if err != nil {
		return "", err
	}
	defer resp.Body.Close()
	if resp.StatusCode != 200 {
		return "", errors.New("Companion ist nicht erreichbar")
	}
	if resp.Header.Get("X-WGB-Companion-Version") != companionVersion {
		return "", errOldCompanionRunning
	}
	return s.URL, nil
}
