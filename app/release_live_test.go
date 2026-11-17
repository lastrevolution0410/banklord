package main

import (
	"net/url"
	"os"
	"testing"
)

// Verifies a real published manifest against the pinned key. Runs only when
// BANKLORD_MANIFEST points to a manifest file (see dev/release-sim.sh).
func TestPublishedManifestVerifies(t *testing.T) {
	path := os.Getenv("BANKLORD_MANIFEST")
	if path == "" {
		t.Skip("BANKLORD_MANIFEST not set")
	}
	body, err := os.ReadFile(path)
	if err != nil {
		t.Fatal(err)
	}
	key, err := pinnedReleaseKey()
	if err != nil {
		t.Fatal(err)
	}
	origin, _ := url.Parse(updateManifestURL)
	r, err := decodeSignedRelease(body, key, origin)
	if err != nil {
		t.Fatal(err)
	}
	if r.Updater.Version != setupVersion || r.Web.Version == "" {
		t.Fatalf("unexpected payload %+v", r)
	}
	body[len(body)/2] ^= 1
	if _, err := decodeSignedRelease(body, key, origin); err == nil {
		t.Fatal("tampered manifest accepted")
	}
}
