package main

import (
	"context"
	"crypto/ed25519"
	"encoding/base64"
	"errors"
	"fmt"
	"io"
	"net/http"
	"net/url"
	"os"
	"path/filepath"
)

// Only the original, cryptographically signed manifest is persisted. Cached
// releases are validated with the pinned Ed25519 public key before each use.
func signedManifestPath() (string, error) {
	dir, err := verifiedCacheDir()
	if err != nil {
		return "", err
	}
	return filepath.Join(dir, "last-verified-manifest.json"), nil
}
func writeSignedManifestCache(b []byte) error {
	if len(b) == 0 || len(b) > maxManifestBytes {
		return errors.New("manifest size invalid")
	}
	path, err := signedManifestPath()
	if err != nil {
		return err
	}
	dir := filepath.Dir(path)
	temp, err := os.CreateTemp(dir, ".signed-manifest-*.tmp")
	if err != nil {
		return err
	}
	tmp := temp.Name()
	defer os.Remove(tmp)
	if err = temp.Chmod(0600); err != nil {
		temp.Close()
		return err
	}
	if _, err = temp.Write(b); err != nil {
		temp.Close()
		return err
	}
	if err = temp.Sync(); err != nil {
		temp.Close()
		return err
	}
	if err = temp.Close(); err != nil {
		return err
	}
	return os.Rename(tmp, path)
}
func loadCachedSignedRelease(pub ed25519.PublicKey, origin *url.URL) (releasePayload, error) {
	path, err := signedManifestPath()
	if err != nil {
		return releasePayload{}, err
	}
	b, err := os.ReadFile(path)
	if err != nil {
		return releasePayload{}, err
	}
	return decodeSignedRelease(b, pub, origin)
}
func pinnedReleaseKey() (ed25519.PublicKey, error) {
	b, err := base64.StdEncoding.DecodeString(updateKeyB64)
	if err != nil || len(b) != ed25519.PublicKeySize {
		return nil, errors.New("öffentlicher Release-Schlüssel defekt")
	}
	return ed25519.PublicKey(b), nil
}

// Preflight calls this ONLINE ONLY (never silently accepting a stale cache).
func fetchReleaseLive(ctx context.Context) (releasePayload, error) {
	pub, err := pinnedReleaseKey()
	if err != nil {
		return releasePayload{}, err
	}
	origin, err := url.Parse(updateManifestURL)
	if err != nil || !updateOriginOK(updateManifestURL, origin) {
		return releasePayload{}, errors.New("Manifest-URL ungültig")
	}
	req, err := http.NewRequestWithContext(ctx, http.MethodGet, updateManifestURL, nil)
	if err != nil {
		return releasePayload{}, err
	}
	resp, err := updateHTTPClient().Do(req)
	if err != nil {
		return releasePayload{}, err
	}
	defer resp.Body.Close()
	if resp.StatusCode != http.StatusOK {
		return releasePayload{}, fmt.Errorf("Manifest HTTP %d", resp.StatusCode)
	}
	b, err := io.ReadAll(io.LimitReader(resp.Body, maxManifestBytes+1))
	if err != nil {
		return releasePayload{}, err
	}
	release, err := decodeSignedRelease(b, pub, origin)
	if err != nil {
		return releasePayload{}, err
	}
	// Failure to cache is not a reason to discard a verified live response.
	_ = writeSignedManifestCache(b)
	return release, nil
}

// Used only by the background service. If the server is unreachable, a signed
// release cached during an earlier successful check remains eligible to be
// installed after WoW exits. This is *not* proof it is globally up to date.
func fetchReleaseBackground(ctx context.Context) (releasePayload, bool, error) {
	live, err := fetchReleaseLive(ctx)
	if err == nil {
		return live, false, nil
	}
	pub, keyErr := pinnedReleaseKey()
	if keyErr != nil {
		return releasePayload{}, false, keyErr
	}
	origin, _ := url.Parse(updateManifestURL)
	cached, cacheErr := loadCachedSignedRelease(pub, origin)
	if cacheErr != nil {
		return releasePayload{}, false, fmt.Errorf("online: %v; signierter Cache: %w", err, cacheErr)
	}
	return cached, true, nil
}
