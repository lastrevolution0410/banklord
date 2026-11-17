package main

import (
	"bytes"
	"crypto/ed25519"
	"crypto/rand"
	"encoding/base64"
	"encoding/json"
	"net/url"
	"strings"
	"testing"
)

func TestSignedCachedManifestKeepsSignature(t *testing.T) {
	t.Setenv("LOCALAPPDATA", t.TempDir())
	pub, priv, err := ed25519.GenerateKey(rand.Reader)
	if err != nil {
		t.Fatal(err)
	}
	origin, _ := url.Parse("https://wow-bank.mgc-nom.net/updates/preview/manifest.json")
	r := releasePayload{Protocol: 1, Channel: "preview", Client: "mop-classic-5.5.4", Addon: releaseFile{"0.6.0", "https://wow-bank.mgc-nom.net/updates/preview/addon.zip", strings.Repeat("a", 64)}, Companion: releaseFile{"0.4.0", "https://wow-bank.mgc-nom.net/updates/preview/companion.exe", strings.Repeat("b", 64)}}
	p, _ := json.Marshal(r)
	env := signedRelease{Payload: base64.StdEncoding.EncodeToString(p), Signature: base64.StdEncoding.EncodeToString(ed25519.Sign(priv, p))}
	b, _ := json.Marshal(env)
	if err := writeSignedManifestCache(b); err != nil {
		t.Fatal(err)
	}
	got, err := loadCachedSignedRelease(pub, origin)
	if err != nil || got.Addon.Version != "0.6.0" {
		t.Fatalf("good cached manifest rejected %v", err)
	}
	bad := bytes.Replace(b, []byte("eyJ"), []byte("e1J"), 1)
	if err := writeSignedManifestCache(bad); err != nil {
		t.Fatal(err)
	}
	if _, err := loadCachedSignedRelease(pub, origin); err == nil {
		t.Fatal("tampered cached manifest accepted")
	}
}
