//go:build !windows

package companion

import (
	"os/exec"
	"runtime"
	"testing"
)

func TestTokenFileFallbackRoundTrip(t *testing.T) {
	if runtime.GOOS == "darwin" {
		t.Skip("macOS uses the login keychain; covered by the manual check")
	}
	if _, err := exec.LookPath("secret-tool"); err == nil {
		t.Skip("secret-tool present")
	}
	sealed, err := protectToken("wgb_tok_123")
	if err != nil || sealed == "wgb_tok_123" {
		t.Fatalf("%q %v", sealed, err)
	}
	plain, err := unprotectToken(sealed)
	if err != nil || plain != "wgb_tok_123" {
		t.Fatalf("%q %v", plain, err)
	}
	if _, err := unprotectToken("AQAAANCMnd8BFdERjHoAwE"); err == nil {
		t.Fatal("foreign DPAPI blob must be rejected")
	}
}
