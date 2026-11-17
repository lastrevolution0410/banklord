package main

import (
	"context"
	"crypto/sha256"
	"encoding/hex"
	"fmt"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"
)

func TestEventLongPollAndFallback(t *testing.T) {
	blob := []byte("changed signed manifest")
	sum := sha256.Sum256(blob)
	digest := hex.EncodeToString(sum[:])
	status := http.StatusOK
	server := httptest.NewTLSServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Path != "/updates/preview/events.php" {
			http.NotFound(w, r)
			return
		}
		if status != http.StatusOK {
			w.WriteHeader(status)
			return
		}
		if r.URL.Query().Get("since") == digest {
			w.WriteHeader(http.StatusNoContent)
			return
		}
		w.Header().Set("Content-Type", "application/json; charset=utf-8")
		fmt.Fprintf(w, `{"digest":%q}`, digest)
	}))
	defer server.Close()
	endpoint := server.URL + "/updates/preview/events.php"
	expectedHost := strings.TrimPrefix(server.URL, "https://")
	check := func(last string) (string, bool, error) {
		return pollEventAtOrigin(context.Background(), server.Client(), endpoint, last, expectedHost)
	}
	got, changed, err := check("")
	if err != nil || !changed || got != digest {
		t.Fatalf("changed: %q %v %v", got, changed, err)
	}
	got, changed, err = check(digest)
	if err != nil || changed || got != digest {
		t.Fatalf("not modified: %q %v %v", got, changed, err)
	}
	status = http.StatusServiceUnavailable
	_, _, err = check(digest)
	if err == nil {
		t.Fatal("push outage must report error, not fake up-to-date status")
	}
	status = http.StatusNotFound
	_, _, err = check(digest)
	if err == nil {
		t.Fatal("missing optional push endpoint must trigger fallback")
	}
	if _, _, err := pollEventAtOrigin(context.Background(), server.Client(), server.URL+"/evil", "", expectedHost); err == nil {
		t.Fatal("untrusted endpoint accepted")
	}
	if _, _, err := pollEventAtOrigin(context.Background(), server.Client(), endpoint, "injection", expectedHost); err == nil {
		t.Fatal("invalid since accepted")
	}
}
