package companion

import (
	"bytes"
	"context"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net/http"
	"net/url"
	"os"
	"path/filepath"
	"regexp"
	"strconv"
	"strings"
	"time"
)

const maxPayload = 4 * 1024 * 1024
const maxResponse = 256 * 1024

var tokenPattern = regexp.MustCompile(`^bl1_[a-f0-9]{64}$`)
var httpClient = &http.Client{
	Timeout:       25 * time.Second,
	CheckRedirect: func(req *http.Request, via []*http.Request) error { return http.ErrUseLastResponse },
}

type Profile struct {
	Server          string `json:"server"`
	GuildID         int64  `json:"guild_id"`
	Source          string `json:"saved_variables_file"`
	IntervalSeconds int    `json:"interval_seconds"`
	EncryptedToken  string `json:"encrypted_token"`
}

func (p Profile) validate() error {
	u, err := url.Parse(p.Server)
	if err != nil || u.Scheme != "https" || u.Hostname() == "" || u.User != nil || u.RawQuery != "" || u.Fragment != "" || u.Path != "" || u.Opaque != "" {
		return errors.New("HTTPS-Adresse ohne Zusatzpfad erforderlich")
	}
	if u.Port() != "" && u.Port() != "443" {
		return errors.New("Nur HTTPS-Port 443 zugelassen")
	}
	if strings.Contains(u.Hostname(), " ") || !strings.Contains(u.Hostname(), ".") {
		return errors.New("Vollständigen Servernamen eingeben")
	}
	if p.GuildID < 1 || p.GuildID > 2147483647 {
		return errors.New("Ungültige Gilden-ID")
	}
	if !strings.EqualFold(filepath.Base(p.Source), "Banklord.lua") || !filepath.IsAbs(p.Source) {
		return errors.New("Absoluten Pfad zur Banklord.lua eingeben")
	}
	if p.IntervalSeconds < 10 || p.IntervalSeconds > 600 {
		return errors.New("Intervall 10 bis 600 Sekunden erforderlich")
	}
	return nil
}
func (p Profile) origin() string { return strings.TrimSuffix(p.Server, "/") }
func (p Profile) endpoint(suffix string) string {
	return p.origin() + "/api/v1/bridge/guilds/" + strconv.FormatInt(p.GuildID, 10) + suffix
}

func stableRead(path string) ([]byte, error) {
	a, err := os.Stat(path)
	if err != nil {
		return nil, err
	}
	if !a.Mode().IsRegular() || a.Size() <= 0 || a.Size() > maxPayload {
		return nil, errors.New("Datei leer, kein regulärer Dateityp oder über 4 MiB")
	}
	if time.Since(a.ModTime()) < 2*time.Second {
		return nil, errors.New("Datei wird noch geschrieben")
	}
	f, err := os.Open(path)
	if err != nil {
		return nil, err
	}
	defer f.Close()
	data, err := io.ReadAll(io.LimitReader(f, maxPayload+1))
	if err != nil {
		return nil, err
	}
	b, err := os.Stat(path)
	if err != nil {
		return nil, err
	}
	if len(data) == 0 || len(data) > maxPayload || a.Size() != b.Size() || a.ModTime() != b.ModTime() || int64(len(data)) != b.Size() {
		return nil, errors.New("Datei während des Lesens verändert")
	}
	return data, nil
}

func request(ctx context.Context, p Profile, token, method, suffix string, body []byte) (int, map[string]any, error) {
	if !tokenPattern.MatchString(token) {
		return 0, nil, errors.New("Ungültiges Bridge-Tokenformat")
	}
	if err := p.validate(); err != nil {
		return 0, nil, err
	}
	req, err := http.NewRequestWithContext(ctx, method, p.endpoint(suffix), bytes.NewReader(body))
	if err != nil {
		return 0, nil, err
	}
	req.Header.Set("Authorization", "Bearer "+token)
	req.Header.Set("Accept", "application/json")
	req.Header.Set("User-Agent", "Banklord-Companion/0.4.0")
	if method == http.MethodPost {
		ct := "text/plain; charset=utf-8"
		if v := ctx.Value(jsonBodyKey{}); v != nil {
			ct = "application/json"
		}
		req.Header.Set("Content-Type", ct)
	}
	resp, err := httpClient.Do(req)
	if err != nil {
		return 0, nil, fmt.Errorf("HTTPS-Verbindung fehlgeschlagen: %w", err)
	}
	defer resp.Body.Close()
	if resp.StatusCode >= 300 && resp.StatusCode < 400 {
		return resp.StatusCode, nil, errors.New("HTTPS-Weiterleitung blockiert")
	}
	out, err := io.ReadAll(io.LimitReader(resp.Body, maxResponse+1))
	if err != nil {
		return resp.StatusCode, nil, err
	}
	if len(out) > maxResponse {
		return resp.StatusCode, nil, errors.New("Serverantwort zu groß")
	}
	var parsed map[string]any
	if json.Unmarshal(out, &parsed) != nil {
		return resp.StatusCode, nil, errors.New("Unerwartete API-Antwort")
	}
	if resp.StatusCode < 200 || resp.StatusCode >= 300 {
		return resp.StatusCode, nil, fmt.Errorf("Server meldet HTTP %d", resp.StatusCode)
	}
	return resp.StatusCode, parsed, nil
}
type jsonBodyKey struct{}

func requestJSON(ctx context.Context, p Profile, token, method, suffix string, body []byte) (int, map[string]any, error) {
	return request(context.WithValue(ctx, jsonBodyKey{}, true), p, token, method, suffix, body)
}

func probe(ctx context.Context, p Profile, token string) error {
	_, v, err := request(ctx, p, token, http.MethodGet, "/status", nil)
	if err != nil {
		return err
	}
	if (v["scope"] != "scan.stage" && v["scope"] != "scan.auto" && v["scope"] != "member.orders") || v["ok"] != true {
		return errors.New("Bridge-Rechte nicht bestätigt")
	}
	return nil
}

type uploadOutcome struct {
	ID, AutoStatus, AutoReason string
	OrdersOnly                 bool
	State                      *ingameState
}

func upload(ctx context.Context, p Profile, token string, payload []byte) (string, error) {
	result, err := uploadWithResult(ctx, p, token, payload)
	return result.ID, err
}
func uploadWithResult(ctx context.Context, p Profile, token string, payload []byte) (uploadOutcome, error) {
	if len(payload) == 0 || len(payload) > maxPayload {
		return uploadOutcome{}, errors.New("Ungültige Importdateigröße")
	}
	_, v, err := request(ctx, p, token, http.MethodPost, "/imports", payload)
	if err != nil {
		return uploadOutcome{}, err
	}
	var state *ingameState
	if raw, present := v["ingame_state"]; present {
		if st, err := decodeState(raw); err == nil {
			state = &st
		}
	}
	obj, ok := v["import"].(map[string]any)
	if !ok {
		// Personal order token: the server never stages inventory for it.
		if state != nil {
			return uploadOutcome{OrdersOnly: true, State: state}, nil
		}
		return uploadOutcome{}, errors.New("Upload nicht bestätigt")
	}
	status, ok := obj["status"].(string)
	if !ok || (status != "pending" && status != "reconciled") {
		return uploadOutcome{}, errors.New("Unerwarteter Importstatus")
	}
	n, ok := obj["stock_changed"].(bool)
	if !ok || n {
		return uploadOutcome{}, errors.New("Unerwartete Serverbuchung")
	}
	result := uploadOutcome{ID: fmt.Sprint(obj["id"]), AutoStatus: "not_enabled", State: state}
	if a, ok := v["auto_reconcile"].(map[string]any); ok {
		if st, ok := a["status"].(string); ok {
			result.AutoStatus = st
		}
		if reason, ok := a["reason"].(string); ok && len(reason) < 120 {
			result.AutoReason = reason
		}
	}
	return result, nil
}
func hash(data []byte) string { s := sha256.Sum256(data); return hex.EncodeToString(s[:]) }
