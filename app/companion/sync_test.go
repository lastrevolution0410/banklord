package companion

import (
	"bytes"
	"context"
	"io"
	"net/http"
	"os"
	"path/filepath"
	"strings"
	"testing"
	"time"
)

type roundTripFunc func(*http.Request) (*http.Response, error)

func (f roundTripFunc) RoundTrip(r *http.Request) (*http.Response, error) { return f(r) }
func testProfile() Profile {
	return Profile{Server: "https://wow-test.example.de", GuildID: 7, Source: filepath.Join(string(filepath.Separator), "tmp", "Banklord.lua"), IntervalSeconds: 15}
}
func testToken() string { return "wgb1_" + strings.Repeat("a", 64) }
func TestValidate(t *testing.T) {
	good := testProfile()
	if err := good.validate(); err != nil {
		t.Fatal(err)
	}
	for _, server := range []string{"http://wow-test.example.de", "https://u:pass@wow-test.example.de", "https://wow-test.example.de/secret", "https://wow-test.example.de?foo=x", "https://wow-test.example.de:8080", "https://badhost"} {
		p := good
		p.Server = server
		if err := p.validate(); err == nil {
			t.Errorf("server incorrectly accepted: %q", server)
		}
	}
	p := good
	p.Source = "other.lua"
	if p.validate() == nil {
		t.Fatal("Non-WGB Lua accepted")
	}
	p = good
	p.IntervalSeconds = 1
	if p.validate() == nil {
		t.Fatal("bad interval accepted")
	}
}
func TestStableRead(t *testing.T) {
	dir := t.TempDir()
	src := filepath.Join(dir, "Banklord.lua")
	if err := os.WriteFile(src, []byte("BanklordDB={}"), 0600); err != nil {
		t.Fatal(err)
	}
	if _, err := stableRead(src); err == nil {
		t.Fatal("File too fresh must be rejected")
	}
	old := time.Now().Add(-10 * time.Second)
	if err := os.Chtimes(src, old, old); err != nil {
		t.Fatal(err)
	}
	got, err := stableRead(src)
	if err != nil || !bytes.Equal(got, []byte("BanklordDB={}")) {
		t.Fatalf("stable read: %v", err)
	}
}
func TestAuthProbeAndUpload(t *testing.T) {
	old := httpClient
	defer func() { httpClient = old }()
	token := testToken()
	requestCount := 0
	httpClient = &http.Client{Transport: roundTripFunc(func(r *http.Request) (*http.Response, error) {
		requestCount++
		if r.URL.Scheme != "https" || r.Header.Get("Authorization") != "Bearer "+token || r.URL.Host != "wow-test.example.de" {
			t.Fatal("not authorized HTTPS request")
		}
		var response string
		if r.Method == "GET" {
			if !strings.HasSuffix(r.URL.Path, "/status") {
				t.Fatal("wrong status endpoint")
			}
			response = `{"ok":true,"scope":"scan.stage","guild_id":7}`
		} else {
			if r.Header.Get("Content-Type") != "text/plain; charset=utf-8" {
				t.Fatal("wrong MIME")
			}
			content, _ := io.ReadAll(r.Body)
			if string(content) != "BanklordDB={}" {
				t.Fatalf("payload corrupted: %q", content)
			}
			response = `{"import":{"id":42,"status":"pending","stock_changed":false}}`
		}
		return &http.Response{StatusCode: 200, Header: make(http.Header), Body: io.NopCloser(strings.NewReader(response)), Request: r}, nil
	})}
	p := testProfile()
	if err := probe(context.Background(), p, token); err != nil {
		t.Fatal(err)
	}
	id, err := upload(context.Background(), p, token, []byte("BanklordDB={}"))
	if err != nil || id != "42" {
		t.Fatalf("import: %s %v", id, err)
	}
	if requestCount != 2 {
		t.Fatal("expected exactly 2 requests")
	}
}
func TestServerRejectsUnconfirmedImportAndRedirect(t *testing.T) {
	old := httpClient
	defer func() { httpClient = old }()
	p := testProfile()
	httpClient = &http.Client{Transport: roundTripFunc(func(r *http.Request) (*http.Response, error) {
		return &http.Response{StatusCode: 200, Header: make(http.Header), Body: io.NopCloser(strings.NewReader(`{"import":{"id":8,"status":"accepted","stock_changed":true}}`)), Request: r}, nil
	})}
	if _, err := upload(context.Background(), p, testToken(), []byte("x")); err == nil {
		t.Fatal("must reject unexpected state mutation")
	}
	httpClient = &http.Client{Transport: roundTripFunc(func(r *http.Request) (*http.Response, error) {
		return &http.Response{StatusCode: 302, Header: http.Header{"Location": []string{"https://evil.example/"}}, Body: io.NopCloser(strings.NewReader("")), Request: r}, nil
	}), CheckRedirect: func(*http.Request, []*http.Request) error { return http.ErrUseLastResponse }}
	if err := probe(context.Background(), p, testToken()); err == nil {
		t.Fatal("redirect must be rejected")
	}
}

func TestAutoScopedProbeAndOutcome(t *testing.T) {
	old := httpClient
	defer func() { httpClient = old }()
	httpClient = &http.Client{Transport: roundTripFunc(func(r *http.Request) (*http.Response, error) {
		response := `{"ok":true,"scope":"scan.auto"}`
		if r.Method == http.MethodPost {
			response = `{"import":{"id":99,"status":"pending","stock_changed":false},"auto_reconcile":{"status":"reconciled","changed_stocks":3}}`
		}
		return &http.Response{StatusCode: 200, Header: make(http.Header), Body: io.NopCloser(strings.NewReader(response)), Request: r}, nil
	})}
	p := testProfile()
	if err := probe(context.Background(), p, testToken()); err != nil {
		t.Fatal(err)
	}
	result, err := uploadWithResult(context.Background(), p, testToken(), []byte("test"))
	if err != nil || result.ID != "99" || result.AutoStatus != "reconciled" {
		t.Fatalf("auto outcome: %#v %v", result, err)
	}
}
