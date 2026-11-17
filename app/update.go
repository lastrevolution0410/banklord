package main

import (
	"context"
	"crypto/ed25519"
	"crypto/sha256"
	"encoding/base64"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"log"
	"net/http"
	"net/url"
	"os"
	"path/filepath"
	"regexp"
	"runtime"
	"strings"
	"time"
)

// updateHost is the official update server. Forks and self-hosters override it at build time:
//
//	go build -ldflags "-X main.updateHost=updates.example.org"
var updateHost = "banklord.mgc-nom.net"

var updateManifestURL = "https://" + updateHost + "/updates/preview/manifest.json"

const (
	updateKeyB64     = "fnurLSrCmhYmaJw6CG0NZtwMka5aEIQWqYC6DmcGMRk="
	maxManifestBytes = 16 << 10
	updateInterval   = 30 * time.Second
)

type releaseFile struct {
	Version string `json:"version"`
	URL     string `json:"url"`
	SHA256  string `json:"sha256"`
}
type releasePayload struct {
	Protocol    int         `json:"protocol"`
	Channel     string      `json:"channel"`
	Client      string      `json:"client"`
	PublishedAt string      `json:"published_at"`
	Addon       releaseFile `json:"addon"`
	Companion   releaseFile `json:"companion"`
	// Optional since 0.9.1: the background updater itself and the website payload
	// (the website applies its own part server-side; clients ignore it).
	Updater releaseFile `json:"updater,omitempty"`
	Web     releaseFile `json:"web,omitempty"`
	// Optional since 1.0.1: the app for macOS and Linux as a zip per platform,
	// keyed "darwin-arm64", "darwin-amd64", "linux-amd64", "linux-arm64".
	Apps map[string]releaseFile `json:"apps,omitempty"`
}

var appPlatformRE = regexp.MustCompile(`^(darwin|linux)-(amd64|arm64)$`)

type signedRelease struct {
	Payload   string `json:"payload"`
	Signature string `json:"signature"`
}

func updateHTTPClient() *http.Client {
	return &http.Client{Timeout: 20 * time.Second, CheckRedirect: func(_ *http.Request, _ []*http.Request) error { return errors.New("Update-Redirect verweigert") }}
}
func updateOriginOK(raw string, origin *url.URL) bool {
	u, err := url.Parse(raw)
	if err != nil || u.Scheme != "https" || u.Host != origin.Host || u.User != nil || u.Fragment != "" || u.RawQuery != "" || u.Opaque != "" {
		return false
	}
	return strings.HasPrefix(u.EscapedPath(), "/updates/preview/") && !strings.Contains(strings.ToLower(u.EscapedPath()), "%2f") && !strings.Contains(u.EscapedPath(), "..")
}
func validateRelease(r releasePayload, origin *url.URL) error {
	if r.Protocol != 1 || r.Channel != "preview" || r.Client != "mop-classic-5.5.4" {
		return errors.New("Update-Protokoll/Channel unbekannt")
	}
	files := []releaseFile{r.Addon, r.Companion}
	for _, f := range []releaseFile{r.Updater, r.Web} {
		if f.Version != "" || f.URL != "" || f.SHA256 != "" {
			files = append(files, f)
		}
	}
	if len(r.Apps) > 8 {
		return errors.New("zu viele Plattform-Pakete")
	}
	for k, f := range r.Apps {
		if !appPlatformRE.MatchString(k) {
			return errors.New("Plattform-Paket unbekannt")
		}
		files = append(files, f)
	}
	for _, f := range files {
		if _, ok := compareVersions(f.Version, "0.0.0"); !ok {
			return errors.New("Update-Version ungültig")
		}
		if len(f.SHA256) != 64 {
			return errors.New("Update-Prüfsumme ungültig")
		}
		if _, err := hex.DecodeString(f.SHA256); err != nil {
			return errors.New("Update-Prüfsumme ungültig")
		}
		if !updateOriginOK(f.URL, origin) {
			return errors.New("Update-Datei nicht von autorisierter HTTPS-Adresse")
		}
	}
	return nil
}
func decodeSignedRelease(body []byte, key ed25519.PublicKey, origin *url.URL) (releasePayload, error) {
	var env signedRelease
	if len(body) > maxManifestBytes || json.Unmarshal(body, &env) != nil {
		return releasePayload{}, errors.New("Manifest ungültig")
	}
	payload, err := base64.StdEncoding.DecodeString(env.Payload)
	if err != nil || len(payload) > maxManifestBytes {
		return releasePayload{}, errors.New("Manifest-Payload ungültig")
	}
	sig, err := base64.StdEncoding.DecodeString(env.Signature)
	if err != nil || !ed25519.Verify(key, payload, sig) {
		return releasePayload{}, errors.New("Update-Signatur ungültig")
	}
	var release releasePayload
	if err = json.Unmarshal(payload, &release); err != nil {
		return releasePayload{}, err
	}
	if err = validateRelease(release, origin); err != nil {
		return releasePayload{}, err
	}
	return release, nil
}
func readRemoteRelease(ctx context.Context, client *http.Client, manifestURL string, pub ed25519.PublicKey) (releasePayload, error) {
	origin, err := url.Parse(manifestURL)
	if err != nil || !updateOriginOK(manifestURL, origin) {
		return releasePayload{}, errors.New("Manifest-Adresse ungültig")
	}
	req, err := http.NewRequestWithContext(ctx, "GET", manifestURL, nil)
	if err != nil {
		return releasePayload{}, err
	}
	resp, err := client.Do(req)
	if err != nil {
		return releasePayload{}, err
	}
	defer resp.Body.Close()
	if resp.StatusCode != 200 {
		return releasePayload{}, fmt.Errorf("Update-Server antwortet %d", resp.StatusCode)
	}
	body, err := io.ReadAll(io.LimitReader(resp.Body, maxManifestBytes+1))
	if err != nil {
		return releasePayload{}, err
	}
	return decodeSignedRelease(body, pub, origin)
}
func fetchRelease(ctx context.Context) (releasePayload, error) {
	return fetchReleaseLive(ctx)
}
func fetchUpdateFile(ctx context.Context, client *http.Client, f releaseFile, max int64) ([]byte, error) {
	req, err := http.NewRequestWithContext(ctx, "GET", f.URL, nil)
	if err != nil {
		return nil, err
	}
	resp, err := client.Do(req)
	if err != nil {
		return nil, err
	}
	defer resp.Body.Close()
	if resp.StatusCode != 200 {
		return nil, fmt.Errorf("Download HTTP %d", resp.StatusCode)
	}
	if resp.ContentLength > max {
		return nil, errors.New("Update-Datei zu groß")
	}
	b, err := io.ReadAll(io.LimitReader(resp.Body, max+1))
	if err != nil {
		return nil, err
	}
	if int64(len(b)) > max {
		return nil, errors.New("Update-Datei zu groß")
	}
	sum := sha256.Sum256(b)
	if !strings.EqualFold(hex.EncodeToString(sum[:]), f.SHA256) {
		return nil, errors.New("Update-Datei Prüfsumme falsch")
	}
	return b, nil
}
func remoteUpdateClient(ctx context.Context, release releasePayload, clientPath string, withCompanion bool) (string, error) {
	unlock, lockErr := acquireUpdateLock()
	if lockErr != nil {
		return "", lockErr
	}
	defer unlock()
	gameRunning, statusErr := wowRunningChecked()
	if statusErr != nil {
		return "", fmt.Errorf("WoW-Prozessprüfung nicht möglich: %w", statusErr)
	}
	addonBytes, err := bundled.ReadFile("assets/addon.zip")
	if err != nil {
		return "", err
	}
	st, err := inspectAddon(clientPath, addonBytes)
	if err != nil {
		return "", err
	}
	if !st.Present {
		return "", errors.New("Addon nicht installiert; zunächst Setup starten")
	}
	if !st.Recognized {
		return "", errors.New("Addon nicht eindeutig erkannt oder neuer; kein automatisches Überschreiben")
	}
	cmp, ok := compareVersions(st.InstalledVersion, release.Addon.Version)
	if !ok {
		return "", errors.New("installierte Version unbekannt")
	}
	msg := "Addon unverändert"
	if cmp < 0 {
		// HTTPS payloads are downloaded and SHA-256 verified even while WoW runs.
		// They are stored outside the game and only installed via atomic swap.
		b, err := cacheVerifiedUpdate(release.Addon, func() ([]byte, error) {
			return fetchUpdateFile(ctx, updateHTTPClient(), release.Addon, 32<<20)
		})
		if err != nil {
			return "", err
		}
		files, err := addonPayloadFiles(b)
		if err != nil {
			return "", err
		}
		if readTOCVersion(files["Banklord.toc"]) != release.Addon.Version {
			return "", errors.New("Paketversion stimmt nicht mit Manifest überein")
		}
		currentTOC, err := os.ReadFile(filepath.Join(clientPath, "Interface", "AddOns", addonDirectory, "Banklord.toc"))
		if err != nil {
			return "", err
		}
		if gameRunning {
			// Changing loaded Lua on disk cannot notify the already running addon,
			// and a manual reload may discard unsaved SavedVariables. Stage only.
			// After the game exits, the watcher performs a mandatory update check.
			_ = currentTOC
			msg = "Update signiert geprüft und zwischengespeichert. Sicherer Austausch automatisch nach WoW-Ende."
		} else {
			outcome, e := performRemoteAddonUpdate(clientPath, b, release.Addon.Version)
			if e != nil {
				if gameRunning {
					msg = "Update heruntergeladen und geprüft; Dateien derzeit gesperrt. Automatischer Wiederholungsversuch."
				} else {
					return "", e
				}
			} else if gameRunning {
				msg = "Addon-Dateien sicher ausgetauscht: " + outcome + "; im Spiel manuell /reload nach Abschluss von Scans/Bestellungen."
			} else {
				msg = "Addon aktualisiert: " + outcome
			}
		}
	}
	// Until the manifest carries per-platform builds, only Windows takes companion binaries from it.
	if withCompanion && runtime.GOOS == "windows" {
		localExe, err := companionPayload()
		if err != nil {
			return msg, err
		}
		cst, err := inspectCompanion(localExe)
		if err != nil {
			return msg, err
		}
		if cst.Present && cst.Recognized {
			cmp, ok := compareVersions(cst.InstalledVersion, release.Companion.Version)
			if ok && cmp < 0 {
				if running, _ := companionIsRunning(); running {
					return msg + "; Companion-Update wartet bis Programm beendet ist", nil
				}
				b, err := cacheVerifiedUpdate(release.Companion, func() ([]byte, error) {
					return fetchUpdateFile(ctx, updateHTTPClient(), release.Companion, 64<<20)
				})
				if err != nil {
					return msg, err
				}
				if _, err := installCompanionVersion(b, release.Companion.Version, false, false); err != nil {
					return msg, err
				}
				msg += "; Companion aktualisiert"
			}
		}
	}
	return msg, nil
}
func performRemoteAddonUpdate(client string, b []byte, version string) (string, error) {
	// Must be recognized before modification, and version strictly newer than installed.
	st, err := inspectAddon(client, b)
	if err != nil {
		return "", err
	}
	if !st.Present || !st.Recognized {
		return "", errors.New("nicht erkannte Addon-Installation")
	}
	cmp, ok := compareVersions(st.InstalledVersion, version)
	if !ok || cmp >= 0 {
		return "", errors.New("kein gültiges Update")
	}
	out, err := installAddon(client, b)
	if err != nil {
		return "", err
	}
	return out.AddonPath, nil
}
func installCompanionVersion(b []byte, version string, auto, launch bool) (string, error) {
	return installCompanionWithVersion(b, version, auto, launch)
}
func knownClientPaths() []string {
	cfg, e := os.UserConfigDir()
	if e != nil {
		return nil
	}
	b, e := os.ReadFile(filepath.Join(cfg, "Banklord", "known_clients.json"))
	if e != nil {
		return nil
	}
	var paths []string
	if json.Unmarshal(b, &paths) != nil {
		return nil
	}
	return paths
}
func checkUpdateAndApply() string {
	ctx, cancel := context.WithTimeout(context.Background(), 90*time.Second)
	defer cancel()
	release, cacheOnly, err := fetchReleaseBackground(ctx)
	if err != nil {
		return "Update-Prüfung: " + err.Error()
	}
	if cacheOnly {
		log.Println("Kein Live-Manifest erreichbar: verwende ausschließlich zuvor verifizierte signierte Version aus Cache. Neue Version derzeit NICHT nachweisbar.")
	}
	paths := knownClientPaths()
	if len(paths) == 0 {
		return "Keine registrierten WoW-Installationen"
	}
	msgs := []string{}
	for _, p := range paths {
		found, ok := candidateInstall(p)
		if !ok || !found.Supported {
			continue
		}
		m, e := remoteUpdateClient(ctx, release, p, true)
		if e != nil {
			msgs = append(msgs, filepath.Base(p)+": "+e.Error())
		} else {
			msgs = append(msgs, filepath.Base(p)+": "+m)
		}
	}
	if len(msgs) == 0 {
		return "Kein unterstütztes Ziel registriert"
	}
	if m := selfUpdateIfNewer(ctx, release); m != "" {
		msgs = append(msgs, m)
	}
	if m := syncUnixCompanion(); m != "" {
		msgs = append(msgs, m)
	}
	return strings.Join(msgs, " | ")
}
func watchUpdates() {
	// Periodic pull: Shared web hosting does not supply unsolicited push to an offline PC.
	for {
		log.Println(checkUpdateAndApply())
		time.Sleep(updateInterval)
	}
}
