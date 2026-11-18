package companion

// Joining by in-game confirmation (server side: backend/src/Claims.php).
// The companion owns an Ed25519 key pair. Only the public key goes into the
// addon (Claim.lua). After a bank manager confirmed this player in game, the
// addon stores the website address in its SavedVariables ("joinOffers"). The
// user clicks "Verbinden" once; the companion signs the server's nonce together
// with exactly that website address and receives its own access.

import (
	"bytes"
	"context"
	"crypto/ed25519"
	"crypto/rand"
	"encoding/hex"
	"encoding/json"
	"errors"
	"io"
	"net/http"
	"net/url"
	"os"
	"path/filepath"
	"regexp"
	"strconv"
	"strings"
)

type joinOffer struct {
	Server string
	Guild  int64
	By     string
	Source string // SavedVariables file the offer was found in
}

func (o joinOffer) key() string { return o.Server + "|" + strconv.FormatInt(o.Guild, 10) }

func claimKeyPath() (string, error) {
	p, err := profilePath()
	if err != nil {
		return "", err
	}
	return filepath.Join(filepath.Dir(p), "claim-key.json"), nil
}

// claimKey loads or creates this PC's key pair (private part sealed like tokens).
func claimKey() (ed25519.PrivateKey, error) {
	path, err := claimKeyPath()
	if err != nil {
		return nil, err
	}
	var stored struct {
		Sealed string `json:"sealed"`
	}
	if raw, err := os.ReadFile(path); err == nil && json.Unmarshal(raw, &stored) == nil {
		if seed, err := unprotectToken(stored.Sealed); err == nil {
			if b, err := hex.DecodeString(seed); err == nil && len(b) == ed25519.SeedSize {
				return ed25519.NewKeyFromSeed(b), nil
			}
		}
	}
	seed := make([]byte, ed25519.SeedSize)
	if _, err := rand.Read(seed); err != nil {
		return nil, err
	}
	sealed, err := protectNamed("claim-key", hex.EncodeToString(seed))
	if err != nil {
		return nil, err
	}
	b, _ := json.Marshal(map[string]string{"sealed": sealed})
	if err := os.MkdirAll(filepath.Dir(path), 0o700); err != nil {
		return nil, err
	}
	if err := os.WriteFile(path, b, 0o600); err != nil {
		return nil, err
	}
	return ed25519.NewKeyFromSeed(seed), nil
}

// addonDirs lists installed Banklord addon folders (from known clients and
// from found SavedVariables files).
func addonDirs() []string {
	seen := map[string]bool{}
	out := []string{}
	add := func(dir string) {
		if fi, err := os.Stat(filepath.Join(dir, "Banklord.toc")); err == nil && fi.Mode().IsRegular() && !seen[strings.ToLower(dir)] {
			seen[strings.ToLower(dir)] = true
			out = append(out, dir)
		}
	}
	if configDir, err := os.UserConfigDir(); err == nil {
		var clients []string
		if raw, err := os.ReadFile(filepath.Join(configDir, "Banklord", "known_clients.json")); err == nil && json.Unmarshal(raw, &clients) == nil {
			for _, c := range clients {
				if filepath.IsAbs(c) {
					add(filepath.Join(filepath.Clean(c), "Interface", "AddOns", "Banklord"))
				}
			}
		}
	}
	for _, sv := range discover() {
		if dir, err := addonDir(sv); err == nil {
			add(dir)
		}
	}
	return out
}

// writeClaimFiles puts the public key into every installed addon.
func writeClaimFiles(pub ed25519.PublicKey) {
	content := []byte("-- Automatisch vom Banklord-Companion erzeugt. Enthält nur den öffentlichen Schlüssel.\nBanklord_Claim = { key = \"" + hex.EncodeToString(pub) + "\" }\n")
	for _, dir := range addonDirs() {
		target := filepath.Join(dir, "Claim.lua")
		if old, err := os.ReadFile(target); err == nil && bytes.Equal(old, content) {
			continue
		}
		tmp := target + ".tmp"
		if os.WriteFile(tmp, content, 0o644) == nil {
			_ = os.Rename(tmp, target)
		}
	}
}

var (
	offersBlock = regexp.MustCompile(`\["joinOffers"\]\s*=\s*\{`)
	offerEntry  = regexp.MustCompile(`\["(https://[A-Za-z0-9.\-]+)\|([0-9]{1,10})"\]\s*=\s*\{([^{}]*)\}`)
	offerBy     = regexp.MustCompile(`\["by"\]\s*=\s*"([^"\\]{1,80})"`)
)

// findOffers reads confirmations the addon stored in SavedVariables.
func findOffers() []joinOffer {
	out := []joinOffer{}
	for _, sv := range discover() {
		if data, err := os.ReadFile(sv); err == nil {
			out = append(out, parseOffers(data, sv)...)
		}
	}
	return out
}

func parseOffers(data []byte, sv string) []joinOffer {
	out := []joinOffer{}
	loc := offersBlock.FindIndex(data)
	if loc == nil {
		return out
	}
	for _, m := range offerEntry.FindAllSubmatch(data[loc[1]:], 20) {
		guild, _ := strconv.ParseInt(string(m[2]), 10, 64)
		o := joinOffer{Server: string(m[1]), Guild: guild, Source: sv}
		if b := offerBy.FindSubmatch(m[3]); b != nil {
			o.By = string(b[1])
		}
		if u, err := url.Parse(o.Server); err == nil && u.Scheme == "https" && u.Path == "" && strings.Contains(u.Hostname(), ".") && guild > 0 {
			out = append(out, o)
		}
	}
	return out
}

type claimGrant struct {
	GuildID int64  `json:"guild_id"`
	Guild   string `json:"guild"`
	Token   string `json:"token"`
	Welcome string `json:"welcome"`
}

func claimCall(ctx context.Context, server, method, path string, body any, out any) error {
	var rd io.Reader
	if body != nil {
		b, _ := json.Marshal(body)
		rd = bytes.NewReader(b)
	}
	req, err := http.NewRequestWithContext(ctx, method, server+"/api/v1"+path, rd)
	if err != nil {
		return err
	}
	req.Header.Set("Content-Type", "application/json")
	req.Header.Set("Accept", "application/json")
	resp, err := httpClient.Do(req)
	if err != nil {
		return err
	}
	defer resp.Body.Close()
	raw, _ := io.ReadAll(io.LimitReader(resp.Body, 65536))
	if resp.StatusCode != 200 {
		var e struct {
			Error string `json:"error"`
		}
		_ = json.Unmarshal(raw, &e)
		if e.Error == "" {
			e.Error = resp.Status
		}
		return errors.New(e.Error)
	}
	return json.Unmarshal(raw, out)
}

var errNotYetConfirmed = errors.New("Die Bestätigung ist noch nicht auf der Website angekommen. Der Bankverwalter muss einmal /reload ausführen; der Companion versucht es automatisch weiter.")

// redeemOffer proves the key to the offer's website and returns the grants.
func redeemOffer(ctx context.Context, key ed25519.PrivateKey, o joinOffer) ([]claimGrant, error) {
	pub := hex.EncodeToString(key.Public().(ed25519.PublicKey))
	var ch struct {
		Nonce *string `json:"nonce"`
	}
	if err := claimCall(ctx, o.Server, http.MethodGet, "/claims/challenge?key="+pub, nil, &ch); err != nil {
		return nil, err
	}
	if ch.Nonce == nil {
		return nil, errNotYetConfirmed
	}
	sig := ed25519.Sign(key, []byte("banklord-claim|"+o.Server+"|"+*ch.Nonce))
	var res struct {
		Grants []claimGrant `json:"grants"`
	}
	if err := claimCall(ctx, o.Server, http.MethodPost, "/claims/redeem", map[string]string{"key": pub, "signature": hex.EncodeToString(sig)}, &res); err != nil {
		return nil, err
	}
	for _, g := range res.Grants {
		if !tokenPattern.MatchString(g.Token) {
			return nil, errors.New("Antwort der Website unvollständig")
		}
	}
	return res.Grants, nil
}
