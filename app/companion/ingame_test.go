package companion

import (
	"os"
	"path/filepath"
	"strings"
	"testing"
)

func TestRenderStateEscapesAndFilters(t *testing.T) {
	st := ingameState{GeneratedAt: 1, Orders: []ingameOrder{{ID: 7, ClientKey: "ig-1700000000-abc123", Status: "reserved",
		Lines: []ingameLine{{ItemID: 2589, Name: "Leinen\"stoff|cffff0000\n]] os.exit()", Requested: 5, Decision: "approved"}}}},
		Outbox: map[string]outboxResult{"ig-1700000000-abc123": {OrderID: 7}, "ig-1700000001-zzz999": {Error: "UNKNOWN_ITEM"}, "../evil": {OrderID: 1}}}
	out := renderState(st)
	for _, want := range []string{`name = "Leinen\"stoff||cffff0000\10]] os.exit()"`, `status = "reserved"`, `["ig-1700000000-abc123"] = { orderId = 7 }`, `error = "UNKNOWN_ITEM"`} {
		if !strings.Contains(out, want) {
			t.Fatalf("missing %q in\n%s", want, out)
		}
	}
	if strings.Contains(out, "evil") {
		t.Fatal("invalid outbox key rendered")
	}
	st.Orders[0].Status = "x\"; os.exit() --"
	if !strings.Contains(renderState(st), `status = "unknown"`) {
		t.Fatal("status not sanitised")
	}
}

func TestWriteServerStateFindsAddonFolder(t *testing.T) {
	root := t.TempDir()
	sv := filepath.Join(root, "_classic_", "WTF", "Account", "ACC1", "SavedVariables", "Banklord.lua")
	addon := filepath.Join(root, "_classic_", "Interface", "AddOns", "Banklord")
	os.MkdirAll(filepath.Dir(sv), 0o755)
	if _, err := writeServerState(sv, ingameState{}); err == nil {
		t.Fatal("expected error without installed addon")
	}
	os.MkdirAll(addon, 0o755)
	os.WriteFile(filepath.Join(addon, "Banklord.toc"), []byte("## Title: x"), 0o644)
	changed, err := writeServerState(sv, ingameState{GeneratedAt: 5})
	if err != nil || !changed {
		t.Fatalf("first write: %v %v", changed, err)
	}
	changed, err = writeServerState(sv, ingameState{GeneratedAt: 9})
	if err != nil || changed {
		t.Fatalf("timestamp-only change must not rewrite: %v %v", changed, err)
	}
	b, _ := os.ReadFile(filepath.Join(addon, "ServerState.lua"))
	if !strings.HasPrefix(string(b), "-- Automatisch") || !strings.Contains(string(b), "Banklord_ServerState = {") {
		t.Fatalf("unexpected file: %s", b)
	}
	if _, err := writeServerState(filepath.Join(root, "x", "Banklord.lua"), ingameState{}); err == nil {
		t.Fatal("wrong structure accepted")
	}
}

func TestRenderManageView(t *testing.T) {
	m := []manageOrder{{ID: 7, Status: "submitted", Requester: "Aria", Recipient: `Bob"-Realm|r`, Lines: []manageLine{{ItemID: 2589, Name: "Leinenstoff", Qty: 5}}, Urgent: true}, {ID: 8, Status: "approved"}}
	out := renderState(ingameState{Manage: &m, ManageResults: map[string]manageResult{"ma-123456-abcdef": {Status: "fulfilled"}, "bad key": {}}})
	for _, want := range []string{`recipient = "Bob\"-Realm||r"`, `qty = 5`, `urgent = true`, `["ma-123456-abcdef"] = { status = "fulfilled"`} {
		if !strings.Contains(out, want) {
			t.Fatalf("missing %q in\n%s", want, out)
		}
	}
	if strings.Contains(out, "bad key") || strings.Contains(renderState(ingameState{}), "manage = {") {
		t.Fatal("invalid key or manager view for members rendered")
	}
}

func TestMergeStatesTagsGuilds(t *testing.T) {
	a := []manageOrder{{ID: 1, Recipient: "A-R"}}
	st := mergeStates([]ingameState{
		{GeneratedAt: 5, Guild: guildRef{"Hausmeister", "Everlook"}, Orders: []ingameOrder{{ID: 9}}, Manage: &a, Outbox: map[string]outboxResult{"ig-123456-aaaaaa": {OrderID: 9}}},
		{GeneratedAt: 7, Guild: guildRef{"Zweite Gilde", "Everlook"}, Outbox: map[string]outboxResult{"ig-123456-bbbbbb": {Error: "X"}}},
	})
	out := renderState(st)
	if st.GeneratedAt != 7 || len(st.Outbox) != 2 || !strings.Contains(out, `guild = "Hausmeister", guildRealm = "Everlook"`) || strings.Count(out, "guild = ") != 2 {
		t.Fatalf("merge wrong:\n%s", out)
	}
}

func TestRenderRulesAndRestock(t *testing.T) {
	g := guildRef{Name: "Gilde", Realm: "Everlook"}
	st := mergeStates([]ingameState{{Guild: g, Rules: &stateRules{ViewOnlyTabs: []int{2, 9}, ExpiryDays: 14, WeeklyLimit: 20, WeeklyUsed: 5},
		Restock: []restockRow{{ItemID: 2589, Name: `Erz"|`, Have: 3, Target: 50, Missing: 47}}}})
	out := renderState(st)
	for _, want := range []string{`viewOnlyTabs = { 2 }`, `weeklyLimit = 20, weeklyUsed = 5`, `missing = 47, guild = "Gilde"`, `name = "Erz\"||"`} {
		if !strings.Contains(out, want) {
			t.Fatalf("missing %q in\n%s", want, out)
		}
	}
}

func TestRenderHistoryAndActivity(t *testing.T) {
	g := guildRef{Name: "Gilde", Realm: "Everlook"}
	st := mergeStates([]ingameState{{Guild: g,
		History:  []historyOrder{{ID: 12, Status: "fulfilled", CreatedAt: 5, UpdatedAt: 9, Requester: "Aria", Recipient: "Aria-Everlook", Urgent: true, Requested: 20, Fulfilled: 20, Summary: `20× Leinen"stoff`}},
		Activity: []activityRow{{Action: "order_deliver", EntityID: 12, At: 9, Actor: "Kaelis", Owner: "Aria", Changed: -1}, {Action: "bad.action!", At: 1}}}})
	out := renderState(st)
	for _, want := range []string{`history = {`, `status = "fulfilled", createdAt = 5, updatedAt = 9`, `summary = "20× Leinen\"stoff", urgent = true, guild = "Gilde"`,
		`action = "order_deliver", id = 12, at = 9, actor = "Kaelis", owner = "Aria", changed = -1, guild = "Gilde"`, `action = "unknown"`} {
		if !strings.Contains(out, want) {
			t.Fatalf("missing %q in\n%s", want, out)
		}
	}
}
