package main

import (
	"bytes"
	"context"
	"crypto/ed25519"
	"crypto/rand"
	"crypto/sha256"
	"encoding/base64"
	"encoding/hex"
	"encoding/json"
	"fmt"
	"net/http"
	"net/http/httptest"
	"net/url"
	"strings"
	"testing"
)

func TestSignedReleaseAndTampering(t *testing.T) {
	pub, priv, e := ed25519.GenerateKey(rand.Reader)
	if e != nil {
		t.Fatal(e)
	}
	origin, _ := url.Parse("https://updates.example.test/updates/preview/manifest.json")
	r := releasePayload{Protocol: 1, Channel: "preview", Client: "mop-classic-5.5.4", Addon: releaseFile{"0.5.3", "https://updates.example.test/updates/preview/a.zip", strings.Repeat("a", 64)}, Companion: releaseFile{"0.3.5", "https://updates.example.test/updates/preview/c.exe", strings.Repeat("b", 64)}}
	p, _ := json.Marshal(r)
	env := signedRelease{base64.StdEncoding.EncodeToString(p), base64.StdEncoding.EncodeToString(ed25519.Sign(priv, p))}
	raw, _ := json.Marshal(env)
	if _, e := decodeSignedRelease(raw, pub, origin); e != nil {
		t.Fatal("valid signature rejected", e)
	}
	env.Payload = base64.StdEncoding.EncodeToString(bytes.Replace(p, []byte("0.5.3"), []byte("9.9.9"), 1))
	tampered, _ := json.Marshal(env)
	if _, e := decodeSignedRelease(tampered, pub, origin); e == nil {
		t.Fatal("tampered payload passed")
	}
	env.Payload = base64.StdEncoding.EncodeToString(p)
	env.Signature = base64.StdEncoding.EncodeToString(bytes.Repeat([]byte{0}, ed25519.SignatureSize))
	tampered, _ = json.Marshal(env)
	if _, e := decodeSignedRelease(tampered, pub, origin); e == nil {
		t.Fatal("invalid signature passed")
	}
	r.Addon.URL = "https://evil.example/updates/preview/a.zip"
	if validateRelease(r, origin) == nil {
		t.Fatal("foreign origin accepted")
	}
	r.Addon.URL = "http://updates.example.test/updates/preview/a.zip"
	if validateRelease(r, origin) == nil {
		t.Fatal("plaintext accepted")
	}
	r.Addon.URL = "https://updates.example.test/updates/preview/../escape"
	if validateRelease(r, origin) == nil {
		t.Fatal("path traversal accepted")
	}
}
func TestDownloadHashAndRedirect(t *testing.T) {
	blob := []byte("verified update")
	sum := sha256.Sum256(blob)
	srv := httptest.NewTLSServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		switch r.URL.Path {
		case "/download":
			_, _ = w.Write(blob)
		case "/redirect":
			http.Redirect(w, r, "https://example.com", 302)
		default:
			http.NotFound(w, r)
		}
	}))
	defer srv.Close()
	f := releaseFile{Version: "0.1.0", URL: srv.URL + "/download", SHA256: hex.EncodeToString(sum[:])}
	client := &http.Client{Transport: srv.Client().Transport, CheckRedirect: updateHTTPClient().CheckRedirect}
	got, e := fetchUpdateFile(context.Background(), client, f, 100)
	if e != nil || !bytes.Equal(got, blob) {
		t.Fatalf("download failed: %v", e)
	}
	f.SHA256 = strings.Repeat("0", 64)
	if _, e := fetchUpdateFile(context.Background(), client, f, 100); e == nil {
		t.Fatal("bad hash passed")
	}
	f.SHA256 = hex.EncodeToString(sum[:])
	f.URL = srv.URL + "/redirect"
	if _, e := fetchUpdateFile(context.Background(), client, f, 100); e == nil {
		t.Fatal("redirect accepted")
	}
	f.URL = srv.URL + "/download"
	if _, e := fetchUpdateFile(context.Background(), client, f, int64(len(blob)-1)); e == nil {
		t.Fatal("oversized download accepted")
	}
	_ = fmt.Sprint(got)
}
