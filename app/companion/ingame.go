package companion

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"net/http"
	"os"
	"path/filepath"
	"regexp"
	"sort"
	"strconv"
	"strings"
)

// In-game order status. The server answers JSON; the companion alone turns it
// into Lua, so a compromised server can never inject code into the addon.

type ingameLine struct {
	ItemID    int64  `json:"item_id"`
	Name      string `json:"name"`
	Requested int64  `json:"requested"`
	Approved  int64  `json:"approved"`
	Fulfilled int64  `json:"fulfilled"`
	Decision  string `json:"decision"`
}
type ingameOrder struct {
	Guild     guildRef     `json:"-"`
	ID        int64        `json:"id"`
	ClientKey string       `json:"client_key"`
	Status    string       `json:"status"`
	CreatedAt int64        `json:"created_at"`
	Lines     []ingameLine `json:"lines"`
}
type outboxResult struct {
	OrderID int64  `json:"order_id,omitempty"`
	Error   string `json:"error,omitempty"`
}
type manageLine struct {
	ItemID int64  `json:"item_id"`
	Name   string `json:"name"`
	Qty    int64  `json:"qty"`
}
type manageOrder struct {
	Guild     guildRef     `json:"-"`
	ID        int64        `json:"id"`
	Status    string       `json:"status"`
	CreatedAt int64        `json:"created_at"`
	Requester string       `json:"requester"`
	Recipient string       `json:"recipient"`
	Approval  int          `json:"approval"`
	Urgent    bool         `json:"urgent"`
	Lines     []manageLine `json:"lines"`
}

// stateRules are the guild's order rules as the addon needs them.
type stateRules struct {
	Guild        guildRef `json:"-"`
	ViewOnlyTabs []int    `json:"viewonly_tabs"`
	ExpiryDays   int      `json:"expiry_days"`
	WeeklyLimit  int64    `json:"weekly_limit"`
	WeeklyUsed   int64    `json:"weekly_used"`
}

// restockRow is one entry of the managers' shopping list (target stock rules).
type restockRow struct {
	Guild   guildRef `json:"-"`
	ItemID  int64    `json:"item_id"`
	Name    string   `json:"name"`
	Have    int64    `json:"have"`
	Target  int64    `json:"target"`
	Missing int64    `json:"missing"`
}
type manageResult struct {
	Status string `json:"status,omitempty"`
	Error  string `json:"error,omitempty"`
}
type guildRef struct {
	Name  string `json:"name"`
	Realm string `json:"realm"`
}
type ingameState struct {
	GeneratedAt   int64                   `json:"generated_at"`
	Guild         guildRef                `json:"guild"`
	Orders        []ingameOrder           `json:"orders"`
	Outbox        map[string]outboxResult `json:"outbox"`
	Manage        *[]manageOrder          `json:"manage"`
	ManageResults map[string]manageResult `json:"manage_results"`
	Feedback      []feedbackRow           `json:"feedback"`
	FeedbackRes   map[string]feedbackRes  `json:"feedback_results"`
	Rules         *stateRules             `json:"rules"`
	Restock       []restockRow            `json:"restock"`
	RulesList     []stateRules            `json:"-"`
	Server        string                  `json:"-"`
	GuildID       int64                   `json:"-"`
	Connections   []stateConnection       `json:"-"`
}

// stateConnection tells the addon which website belongs to which guild, so a
// bank manager can answer join requests with the right address.
type stateConnection struct {
	Server  string
	GuildID int64
	Guild   guildRef
}

var manageKeyPattern = regexp.MustCompile(`^ma-[0-9]{6,12}-[a-z0-9]{6}$`)

// feedbackKeyPattern matches the addon's help & feedback outbox keys.
var feedbackKeyPattern = regexp.MustCompile(`^fb-[0-9]{6,12}-[a-z0-9]{6}$`)

type feedbackRow struct {
	Key    string `json:"key"`
	Number int64  `json:"number"`
	Kind   string `json:"kind"`
	Title  string `json:"title"`
	Status string `json:"status"`
	Reply  string `json:"reply"`
}

type feedbackRes struct {
	Status string `json:"status"`
	Error  string `json:"error"`
}

func guildFields(g guildRef) string {
	if g.Name == "" {
		return ""
	}
	return fmt.Sprintf(" guild = %s, guildRealm = %s,", luaString(clip(g.Name, 80)), luaString(clip(g.Realm, 100)))
}

func clip(s string, n int) string {
	if r := []rune(s); len(r) > n {
		return string(r[:n])
	}
	return s
}

var (
	clientKeyPattern = regexp.MustCompile(`^ig-[0-9]{6,12}-[a-z0-9]{6}$`)
	codeWordPattern  = regexp.MustCompile(`^[A-Za-z_]{1,40}$`)
)

func decodeState(raw any) (ingameState, error) {
	var st ingameState
	if raw == nil {
		return st, errors.New("Kein Bestellstatus in der Antwort")
	}
	b, err := json.Marshal(raw)
	if err != nil {
		return st, err
	}
	if err := json.Unmarshal(b, &st); err != nil {
		return st, errors.New("Bestellstatus unlesbar")
	}
	if len(st.Orders) > 50 || (st.Manage != nil && len(*st.Manage) > 60) {
		return st, errors.New("Bestellstatus zu groß")
	}
	return st, nil
}

func fetchState(ctx context.Context, p Profile, token string) (ingameState, error) {
	_, v, err := request(ctx, p, token, http.MethodGet, "/state", nil)
	if err != nil {
		return ingameState{}, err
	}
	return decodeState(v["ingame_state"])
}

// addonDir derives <client>/Interface/AddOns/Banklord from
// <client>/WTF/Account/<NAME>/SavedVariables/Banklord.lua.
func addonDir(source string) (string, error) {
	sv := filepath.Dir(source)
	account := filepath.Dir(filepath.Dir(sv))
	wtf := filepath.Dir(account)
	if !strings.EqualFold(filepath.Base(sv), "SavedVariables") || !strings.EqualFold(filepath.Base(account), "Account") || !strings.EqualFold(filepath.Base(wtf), "WTF") {
		return "", errors.New("SavedVariables-Pfad hat nicht die erwartete WTF-Struktur")
	}
	dir := filepath.Join(filepath.Dir(wtf), "Interface", "AddOns", "Banklord")
	if fi, err := os.Stat(filepath.Join(dir, "Banklord.toc")); err != nil || !fi.Mode().IsRegular() {
		return "", errors.New("Addon-Ordner Banklord nicht gefunden")
	}
	return dir, nil
}

// luaString quotes s for Lua and neutralises WoW's | escape sequences.
func luaString(s string) string {
	var b strings.Builder
	b.WriteByte('"')
	for _, r := range s {
		switch {
		case r == '\\':
			b.WriteString(`\\`)
		case r == '"':
			b.WriteString(`\"`)
		case r == '|':
			b.WriteString("||")
		case r < 0x20 || r == 0x7f:
			b.WriteString(`\` + strconv.Itoa(int(r)))
		default:
			b.WriteRune(r)
		}
	}
	b.WriteByte('"')
	return b.String()
}

func word(s string) string {
	if codeWordPattern.MatchString(s) {
		return s
	}
	return "unknown"
}

func renderState(st ingameState) string {
	var b strings.Builder
	b.WriteString("-- Automatisch vom Banklord-Companion erzeugt. Nicht bearbeiten.\n")
	fmt.Fprintf(&b, "Banklord_ServerState = {\n  generatedAt = %d,\n  orders = {\n", st.GeneratedAt)
	for _, o := range st.Orders {
		if !clientKeyPattern.MatchString(o.ClientKey) && o.ClientKey != "" {
			o.ClientKey = ""
		}
		fmt.Fprintf(&b, "    { id = %d, clientKey = %s, status = %s, createdAt = %d,%s lines = {\n",
			o.ID, luaString(o.ClientKey), luaString(word(o.Status)), o.CreatedAt, guildFields(o.Guild))
		for _, l := range o.Lines {
			name := l.Name
			if len([]rune(name)) > 80 {
				name = string([]rune(name)[:80])
			}
			fmt.Fprintf(&b, "      { itemId = %d, name = %s, requested = %d, approved = %d, fulfilled = %d, decision = %s },\n",
				l.ItemID, luaString(name), l.Requested, l.Approved, l.Fulfilled, luaString(word(l.Decision)))
		}
		b.WriteString("    } },\n")
	}
	b.WriteString("  },\n  outbox = {\n")
	keys := make([]string, 0, len(st.Outbox))
	for k := range st.Outbox {
		if clientKeyPattern.MatchString(k) {
			keys = append(keys, k)
		}
	}
	sort.Strings(keys)
	for _, k := range keys {
		r := st.Outbox[k]
		if r.OrderID > 0 {
			fmt.Fprintf(&b, "    [%s] = { orderId = %d },\n", luaString(k), r.OrderID)
		} else {
			fmt.Fprintf(&b, "    [%s] = { error = %s },\n", luaString(k), luaString(word(r.Error)))
		}
	}
	b.WriteString("  },\n  manageResults = {\n")
	keys = keys[:0]
	for k := range st.ManageResults {
		if manageKeyPattern.MatchString(k) {
			keys = append(keys, k)
		}
	}
	sort.Strings(keys)
	for _, k := range keys {
		r := st.ManageResults[k]
		fmt.Fprintf(&b, "    [%s] = { status = %s, error = %s },\n", luaString(k), luaString(word(r.Status)), luaString(word(r.Error)))
	}
	b.WriteString("  },\n")
	if st.Manage != nil {
		b.WriteString("  manage = {\n")
		for _, o := range *st.Manage {
			approval := o.Approval
			if approval < 0 || approval > 2 {
				approval = 1
			}
			urgent := ""
			if o.Urgent {
				urgent = " urgent = true,"
			}
			fmt.Fprintf(&b, "    { id = %d, status = %s, createdAt = %d, requester = %s, recipient = %s, approval = %d,%s%s lines = {\n",
				o.ID, luaString(word(o.Status)), o.CreatedAt, luaString(clip(o.Requester, 60)), luaString(clip(o.Recipient, 80)), approval, urgent, guildFields(o.Guild))
			for i, l := range o.Lines {
				if i >= 12 {
					break
				}
				fmt.Fprintf(&b, "      { itemId = %d, name = %s, qty = %d },\n", l.ItemID, luaString(clip(l.Name, 80)), l.Qty)
			}
			b.WriteString("    } },\n")
		}
		b.WriteString("  },\n")
	}
	if len(st.RulesList) > 0 {
		b.WriteString("  rules = {\n")
		for _, r := range st.RulesList {
			tabs := make([]string, 0, len(r.ViewOnlyTabs))
			for _, t := range r.ViewOnlyTabs {
				if t >= 1 && t <= 8 {
					tabs = append(tabs, fmt.Sprint(t))
				}
			}
			fmt.Fprintf(&b, "    {%s viewOnlyTabs = { %s }, expiryDays = %d, weeklyLimit = %d, weeklyUsed = %d },\n",
				guildFields(r.Guild), strings.Join(tabs, ", "), r.ExpiryDays, r.WeeklyLimit, r.WeeklyUsed)
		}
		b.WriteString("  },\n")
	}
	if len(st.Restock) > 0 {
		b.WriteString("  restock = {\n")
		for i, r := range st.Restock {
			if i >= 60 {
				break
			}
			fmt.Fprintf(&b, "    { itemId = %d, name = %s, have = %d, target = %d, missing = %d,%s },\n",
				r.ItemID, luaString(clip(r.Name, 80)), r.Have, r.Target, r.Missing, guildFields(r.Guild))
		}
		b.WriteString("  },\n")
	}
	if len(st.Feedback) > 0 {
		b.WriteString("  feedback = {\n")
		for i, f := range st.Feedback {
			if i >= 10 {
				break
			}
			key := f.Key
			if !feedbackKeyPattern.MatchString(key) {
				key = ""
			}
			fmt.Fprintf(&b, "    { key = %s, number = %d, kind = %s, title = %s, status = %s, reply = %s },\n",
				luaString(key), f.Number, luaString(word(f.Kind)), luaString(clip(f.Title, 80)), luaString(word(f.Status)), luaString(clip(f.Reply, 300)))
		}
		b.WriteString("  },\n")
	}
	if len(st.FeedbackRes) > 0 {
		keys := make([]string, 0, len(st.FeedbackRes))
		for k := range st.FeedbackRes {
			if feedbackKeyPattern.MatchString(k) {
				keys = append(keys, k)
			}
		}
		sort.Strings(keys)
		b.WriteString("  feedbackResults = {\n")
		for _, k := range keys {
			r := st.FeedbackRes[k]
			fmt.Fprintf(&b, "    [%s] = { status = %s, error = %s },\n", luaString(k), luaString(word(r.Status)), luaString(word(r.Error)))
		}
		b.WriteString("  },\n")
	}
	if len(st.Connections) > 0 {
		b.WriteString("  connections = {\n")
		for _, c := range st.Connections {
			if !strings.HasPrefix(c.Server, "https://") || c.GuildID <= 0 || c.Guild.Name == "" {
				continue
			}
			fmt.Fprintf(&b, "    { server = %s, guildId = %d, guild = %s, guildRealm = %s },\n",
				luaString(clip(c.Server, 200)), c.GuildID, luaString(clip(c.Guild.Name, 80)), luaString(clip(c.Guild.Realm, 80)))
		}
		b.WriteString("  },\n")
	}
	b.WriteString("}\n")
	return b.String()
}

// writeServerState replaces ServerState.lua atomically; returns false if unchanged.
func writeServerState(source string, st ingameState) (bool, error) {
	dir, err := addonDir(source)
	if err != nil {
		return false, err
	}
	target := filepath.Join(dir, "ServerState.lua")
	content := []byte(renderState(st))
	if old, err := os.ReadFile(target); err == nil && stripGenerated(old) == stripGenerated(content) {
		return false, nil
	}
	tmp, err := os.CreateTemp(dir, ".ServerState-*.tmp")
	if err != nil {
		return false, err
	}
	defer os.Remove(tmp.Name())
	if _, err := tmp.Write(content); err != nil {
		tmp.Close()
		return false, err
	}
	if err := tmp.Close(); err != nil {
		return false, err
	}
	return true, os.Rename(tmp.Name(), target)
}

// stripGenerated ignores the timestamp so identical states do not rewrite the file.
var generatedLine = regexp.MustCompile(`(?m)^  generatedAt = [0-9]+,$`)

func stripGenerated(b []byte) string { return generatedLine.ReplaceAllString(string(b), "") }

// mergeStates combines the answers of several guild connections into the one
// ServerState.lua the addon reads; each order keeps its guild tag.
func mergeStates(states []ingameState) ingameState {
	out := ingameState{Outbox: map[string]outboxResult{}, ManageResults: map[string]manageResult{}}
	for _, st := range states {
		if st.GeneratedAt > out.GeneratedAt {
			out.GeneratedAt = st.GeneratedAt
		}
		if st.Server != "" {
			out.Connections = append(out.Connections, stateConnection{Server: st.Server, GuildID: st.GuildID, Guild: st.Guild})
		}
		for _, o := range st.Orders {
			o.Guild = st.Guild
			out.Orders = append(out.Orders, o)
		}
		for k, v := range st.Outbox {
			out.Outbox[k] = v
		}
		for k, v := range st.ManageResults {
			out.ManageResults[k] = v
		}
		if st.Rules != nil {
			r := *st.Rules
			r.Guild = st.Guild
			out.RulesList = append(out.RulesList, r)
		}
		for _, r := range st.Restock {
			r.Guild = st.Guild
			out.Restock = append(out.Restock, r)
		}
		out.Feedback = append(out.Feedback, st.Feedback...)
		for k, v := range st.FeedbackRes {
			if out.FeedbackRes == nil {
				out.FeedbackRes = map[string]feedbackRes{}
			}
			out.FeedbackRes[k] = v
		}
		if st.Manage != nil {
			if out.Manage == nil {
				out.Manage = &[]manageOrder{}
			}
			for _, o := range *st.Manage {
				o.Guild = st.Guild
				*out.Manage = append(*out.Manage, o)
			}
		}
	}
	return out
}
