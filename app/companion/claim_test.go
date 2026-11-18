package companion

import (
	"context"
	"crypto/ed25519"
	"encoding/hex"
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"
)

func TestParseOffersAcceptsOnlyHTTPSOrigins(t *testing.T) {
	sv := []byte(`BanklordDB = {
 ["joinOffers"] = {
  ["https://bank.example.org|3"] = { ["by"] = "Martin", ["seen"] = 1 },
  ["http://evil.example.org|4"] = { ["by"] = "X" },
  ["https://bank.example.org/path|5"] = { },
 },
}`)
	got := parseOffers(sv, "x.lua")
	if len(got) != 1 || got[0].Server != "https://bank.example.org" || got[0].Guild != 3 || got[0].By != "Martin" {
		t.Fatalf("offers: %+v", got)
	}
}

func TestRedeemSignsNonceWithOrigin(t *testing.T) {
	_, key, _ := ed25519.GenerateKey(nil)
	var srv *httptest.Server
	srv = httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		switch r.URL.Path {
		case "/api/v1/claims/challenge":
			_ = json.NewEncoder(w).Encode(map[string]any{"nonce": "abc"})
		case "/api/v1/claims/redeem":
			var in map[string]string
			_ = json.NewDecoder(r.Body).Decode(&in)
			pub, _ := hex.DecodeString(in["key"])
			sig, _ := hex.DecodeString(in["signature"])
			if !ed25519.Verify(pub, []byte("banklord-claim|"+srv.URL+"|abc"), sig) {
				w.WriteHeader(422)
				_, _ = w.Write([]byte(`{"error":"CLAIM_SIGNATURE_INVALID"}`))
				return
			}
			_ = json.NewEncoder(w).Encode(map[string]any{"grants": []map[string]any{{"guild_id": 3, "guild": "Test", "token": "bl1_" + strings.Repeat("a", 64)}}})
		}
	}))
	defer srv.Close()
	grants, err := redeemOffer(context.Background(), key, joinOffer{Server: srv.URL, Guild: 3})
	if err != nil || len(grants) != 1 || grants[0].GuildID != 3 {
		t.Fatalf("redeem: %v %+v", err, grants)
	}
}

func TestRedeemWaitsWhileNotConfirmed(t *testing.T) {
	_, key, _ := ed25519.GenerateKey(nil)
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		_, _ = w.Write([]byte(`{"nonce":null,"guilds":[]}`))
	}))
	defer srv.Close()
	if _, err := redeemOffer(context.Background(), key, joinOffer{Server: srv.URL, Guild: 3}); err != errNotYetConfirmed {
		t.Fatalf("want not-yet-confirmed, got %v", err)
	}
}

func TestRenderConnections(t *testing.T) {
	st := mergeStates([]ingameState{
		{Server: "https://bank.example.org", GuildID: 3, Guild: guildRef{Name: "Nom", Realm: "Everlook"}},
		{Server: "https://other.example.org", GuildID: 9, Guild: guildRef{Name: "Zwei\"]] os.exit()", Realm: "X"}},
	})
	out := renderState(st)
	if !strings.Contains(out, `{ server = "https://bank.example.org", guildId = 3, guild = "Nom", guildRealm = "Everlook" }`) {
		t.Fatalf("connection missing:\n%s", out)
	}
	if !strings.Contains(out, `guild = "Zwei\"]] os.exit()"`) {
		t.Fatalf("unescaped guild name:\n%s", out)
	}
}

func TestRenderFeedback(t *testing.T) {
	st := mergeStates([]ingameState{{
		Feedback:    []feedbackRow{{Key: "fb-1700000000-abc123", Number: 12, Kind: "bug", Title: "Fenster \"offen\"", Status: "planned", Reply: "Kommt bald.\nDanke"}, {Key: "evil\"]]", Kind: "x y", Status: "new"}},
		FeedbackRes: map[string]feedbackRes{"fb-1700000000-abc123": {Status: "new"}, "bad key": {Status: "new"}},
	}})
	out := renderState(st)
	if !strings.Contains(out, `{ key = "fb-1700000000-abc123", number = 12, kind = "bug", title = "Fenster \"offen\"", status = "planned", reply = "Kommt bald.\10Danke" }`) {
		t.Fatalf("feedback row missing:\n%s", out)
	}
	if !strings.Contains(out, `key = "", number = 0, kind = "unknown"`) || strings.Contains(out, "bad key") {
		t.Fatalf("invalid keys not filtered:\n%s", out)
	}
	if !strings.Contains(out, `["fb-1700000000-abc123"] = { status = "new", error = "unknown" }`) {
		t.Fatalf("feedback result missing:\n%s", out)
	}
}
