//go:build !windows

package companion

import (
	"os/exec"
	"runtime"
	"strings"
	"testing"
)

func TestTokenFileFallbackRoundTrip(t *testing.T) {
	if runtime.GOOS == "darwin" {
		t.Skip("macOS uses the login keychain; covered by the manual check")
	}
	if _, err := exec.LookPath("secret-tool"); err == nil {
		t.Skip("secret-tool present")
	}
	sealed, err := protectToken("bl_tok_123")
	if err != nil || sealed == "bl_tok_123" {
		t.Fatalf("%q %v", sealed, err)
	}
	plain, err := unprotectToken(sealed)
	if err != nil || plain != "bl_tok_123" {
		t.Fatalf("%q %v", plain, err)
	}
	if _, err := unprotectToken("AQAAANCMnd8BFdERjHoAwE"); err == nil {
		t.Fatal("foreign DPAPI blob must be rejected")
	}
}

// Guilds of another game version read their own Banklord.lua; the rest share the main file.
func TestBySourceSeparatesGameVersions(t *testing.T) {
	if runtime.GOOS == "darwin" {
		t.Skip("macOS uses the login keychain")
	}
	if _, err := exec.LookPath("secret-tool"); err == nil {
		t.Skip("secret-tool present")
	}
	t.Setenv("HOME", t.TempDir())
	t.Setenv("XDG_CONFIG_HOME", t.TempDir())
	tok := "bl1_" + strings.Repeat("a", 64)
	classic := "/games/World of Warcraft/_classic_/WTF/Account/X/SavedVariables/Banklord.lua"
	retail := "/games/World of Warcraft/_retail_/WTF/Account/X/SavedVariables/Banklord.lua"
	seal := func(g int64) string {
		s, err := protectNamed(linkAccount("https://b.example.org", g), tok)
		if err != nil {
			t.Fatal(err)
		}
		return s
	}
	p := Profile{Server: "https://a.example.org", GuildID: 1, Source: classic, IntervalSeconds: 15,
		Extra: []Link{{Server: "https://b.example.org", GuildID: 2, EncryptedToken: seal(2)},
			{Server: "https://b.example.org", GuildID: 3, EncryptedToken: seal(3), Source: retail}}}
	groups := p.bySource(tok)
	if len(groups) != 2 || groups[0].source != classic || len(groups[0].conns) != 2 || groups[1].source != retail || groups[1].conns[0].p.GuildID != 3 {
		t.Fatalf("unexpected groups: %+v", groups)
	}
	if clientLabel(retail) != "Retail" || clientLabel(classic) != "MoP Classic" {
		t.Fatal("client labels")
	}
}
