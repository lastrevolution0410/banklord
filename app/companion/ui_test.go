package companion

import (
	"net/http"
	"net/http/httptest"
	"net/url"
	"strings"
	"testing"
)

func TestLocalUIHostOriginAndSecret(t *testing.T) {
	c := &controller{base: "http://127.0.0.1:18888", uiKey: "unit-test-route-key", csrfToken: "independent-unit-test-csrf-token", token: testToken(), status: "Bereit", profile: testProfile()}
	path := c.base + "/ui/" + c.uiKey

	get := httptest.NewRecorder()
	c.handle(get, httptest.NewRequest(http.MethodGet, path, nil))
	if get.Header().Get("X-BL-Companion-Version") != companionVersion {
		t.Fatal("version header missing or outdated")
	}
	if get.Code != http.StatusOK {
		t.Fatalf("GET failed %d", get.Code)
	}
	if strings.Contains(get.Body.String(), testToken()) {
		t.Fatal("Bridge token leaked into HTML")
	}
	if !strings.Contains(get.Body.String(), `name="csrf" value="independent-unit-test-csrf-token"`) {
		t.Fatal("CSRF field missing")
	}
	if !strings.Contains(get.Header().Get("Content-Security-Policy"), "form-action 'self'") {
		t.Fatal("missing CSP")
	}
	if get.Header().Get("Referrer-Policy") != "no-referrer" {
		t.Fatal("Referrer policy missing")
	}

	evilHost := httptest.NewRequest(http.MethodGet, path, nil)
	evilHost.Host = "attacker.example"
	evilResp := httptest.NewRecorder()
	c.handle(evilResp, evilHost)
	if evilResp.Code != http.StatusForbidden {
		t.Fatal("Host header protection bypass")
	}

	post := func(origin, fetchSite, csrf, contentType string) int {
		vals := url.Values{"action": {"refresh"}, "csrf": {csrf}}
		req := httptest.NewRequest(http.MethodPost, path, strings.NewReader(vals.Encode()))
		if contentType == "" {
			contentType = "application/x-www-form-urlencoded"
		}
		req.Header.Set("Content-Type", contentType)
		if origin != "" {
			req.Header.Set("Origin", origin)
		}
		if fetchSite != "" {
			req.Header.Set("Sec-Fetch-Site", fetchSite)
		}
		w := httptest.NewRecorder()
		c.handle(w, req)
		return w.Code
	}

	valid := c.csrfToken
	for name, test := range map[string]struct {
		origin, fetch, csrf, contentType string
		expected                         int
	}{
		"normal same-origin POST":                   {c.base, "same-origin", valid, "", http.StatusOK},
		"browser missing Origin":                    {"", "same-origin", valid, "", http.StatusOK},
		"browser missing Origin and fetch metadata": {"", "", valid, "", http.StatusOK},
		"alternate localhost origin with CSRF":      {"http://localhost:18888", "same-site", valid, "", http.StatusOK},
		"explicit Origin null with CSRF":            {"null", "", valid, "", http.StatusOK},
		"browser proxy origin with CSRF":            {"https://example.net", "cross-site", valid, "", http.StatusOK},
		"cross-site no CSRF":                        {"https://attacker.example", "cross-site", "", "", http.StatusForbidden},
		"no CSRF":                                   {"", "", "", "", http.StatusForbidden},
		"wrong CSRF":                                {c.base, "same-origin", "attacker", "", http.StatusForbidden},
		"wrong form type":                           {c.base, "same-origin", valid, "text/plain", http.StatusUnsupportedMediaType},
	} {
		t.Run(name, func(t *testing.T) {
			actual := post(test.origin, test.fetch, test.csrf, test.contentType)
			if actual != test.expected {
				t.Errorf("got HTTP %d; want HTTP %d", actual, test.expected)
			}
		})
	}
}
