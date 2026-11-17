package companion

import (
	"errors"
	"io"
	"net/http"
	"net/http/httptest"
	"net/url"
	"path/filepath"
	"strings"
	"testing"
)

func TestBrowserRoundtripWithUnexpectedOrigin(t *testing.T) {
	c := &controller{uiKey: "test-secret-url", csrfToken: "test-secret-csrf", status: "Bereit", profile: testProfile()}
	mux := http.NewServeMux()
	mux.HandleFunc("/ui/"+c.uiKey, c.handle)
	mux.HandleFunc("/", http.NotFound)
	server := httptest.NewServer(mux)
	defer server.Close()
	c.base = server.URL
	client := &http.Client{}
	get, err := client.Get(server.URL + "/ui/" + c.uiKey)
	if err != nil {
		t.Fatal(err)
	}
	page, err := io.ReadAll(get.Body)
	get.Body.Close()
	if err != nil {
		t.Fatal(err)
	}
	if get.StatusCode != http.StatusOK || len(page) == 0 || get.Header.Get("X-WGB-Companion-Version") != companionVersion {
		t.Fatalf("version not served; code=%d", get.StatusCode)
	}
	for _, test := range []struct {
		origin, csrf string
		want         int
	}{
		{"null", "test-secret-csrf", http.StatusOK},
		{"http://localhost:12345", "test-secret-csrf", http.StatusOK},
		{"https://random.browser.extension", "test-secret-csrf", http.StatusOK},
		{"https://random.browser.extension", "incorrect", http.StatusForbidden},
		{"null", "", http.StatusForbidden},
	} {
		vals := url.Values{"action": {"refresh"}, "csrf": {test.csrf}}
		req, err := http.NewRequest(http.MethodPost, server.URL+"/ui/"+c.uiKey, strings.NewReader(vals.Encode()))
		if err != nil {
			t.Fatal(err)
		}
		req.Header.Set("Origin", test.origin)
		req.Header.Set("Sec-Fetch-Site", "cross-site")
		req.Header.Set("Content-Type", "application/x-www-form-urlencoded")
		resp, err := client.Do(req)
		if err != nil {
			t.Fatal(err)
		}
		io.Copy(io.Discard, resp.Body)
		resp.Body.Close()
		if resp.StatusCode != test.want {
			t.Errorf("origin %q csrf %q: got %d want %d", test.origin, test.csrf, resp.StatusCode, test.want)
		}
	}
	// A different pathname does not expose the local admin panel.
	resp, err := client.Get(server.URL + "/ui/not-the-secret")
	if err != nil {
		t.Fatal(err)
	}
	defer resp.Body.Close()
	if resp.StatusCode != http.StatusNotFound {
		t.Fatalf("unprotected admin path: %d", resp.StatusCode)
	}
}

func TestLauncherRejectsOlderRunningVersion(t *testing.T) {
	t.Setenv("XDG_CONFIG_HOME", t.TempDir())
	old := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) { w.WriteHeader(200) }))
	defer old.Close()
	addr, _ := url.Parse(old.URL)
	oldURL := "http://127.0.0.1:" + addr.Port() + "/ui/old-session"
	if err := storeSession(oldURL); err != nil {
		t.Fatal(err)
	}
	if _, err := existingSession(); !errors.Is(err, errOldCompanionRunning) {
		t.Fatalf("old process not detected: %v", err)
	}
	// Keep test contained in the ephemeral temp config location.
	p, err := sessionPath()
	if err != nil {
		t.Fatal(err)
	}
	if !strings.HasPrefix(p, filepath.Clean(strings.TrimSuffix(p, "session.json"))) {
		t.Fatal("invalid session path")
	}
}

func TestLauncherReusesOnlyCurrentVersion(t *testing.T) {
	t.Setenv("XDG_CONFIG_HOME", t.TempDir())
	cur := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("X-WGB-Companion-Version", companionVersion)
		w.WriteHeader(200)
	}))
	defer cur.Close()
	addr, _ := url.Parse(cur.URL)
	sessionURL := "http://127.0.0.1:" + addr.Port() + "/ui/current-session"
	if err := storeSession(sessionURL); err != nil {
		t.Fatal(err)
	}
	if got, err := existingSession(); err != nil || got != sessionURL {
		t.Fatalf("current process wasn't reused: %s %v", got, err)
	}
}
