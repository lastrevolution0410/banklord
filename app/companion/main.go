package companion

import (
	"context"
	"crypto/ed25519"
	"crypto/rand"
	"crypto/subtle"
	"encoding/hex"
	"errors"
	"fmt"
	"html/template"
	"log"
	"mime"
	"net"
	"net/http"
	"os"
	"os/exec"
	"path/filepath"
	"runtime"
	"strconv"
	"strings"
	"sync"
	"time"
)

const companionVersion = "0.12.0"

type controller struct {
	mu           sync.Mutex
	profile      Profile
	token        string
	active       bool
	next         time.Time
	lastHashes   map[string]string // per Banklord.lua
	nextState    time.Time
	nextBeat     time.Time
	lastOutbox   map[string]outboxResult
	lastManage   map[string]manageResult
	claimKey     ed25519.PrivateKey
	offers       []joinOffer
	notified     map[string]bool
	autoRetry    map[string]bool
	nextClaim    time.Time
	status       string
	lastUploaded string
	messages     []string
	server       *http.Server
	uiKey        string
	csrfToken    string
	base         string
}

func (c *controller) message(s string) {
	c.mu.Lock()
	defer c.mu.Unlock()
	c.status = s
	c.messages = append([]string{time.Now().Format("15:04:05") + " · " + s}, c.messages...)
	if len(c.messages) > 8 {
		c.messages = c.messages[:8]
	}
}
func (c *controller) run(ctx context.Context) {
	ticker := time.NewTicker(2 * time.Second)
	defer ticker.Stop()
	for {
		select {
		case <-ctx.Done():
			return
		case <-ticker.C:
		}
		c.claimTick(ctx)
		c.mu.Lock()
		if !c.active || time.Now().Before(c.next) {
			c.mu.Unlock()
			continue
		}
		p, tok := c.profile, c.token
		c.next = time.Now().Add(time.Duration(p.IntervalSeconds) * time.Second)
		c.mu.Unlock()
		if time.Now().After(c.nextBeat) {
			c.nextBeat = time.Now().Add(30 * time.Minute)
			go heartbeat(ctx, p, tok)
		}
		// One Banklord.lua per WoW installation: each file goes only to the
		// guilds that play there, and their status is written back next to it.
		unchanged := []sourceGroup{}
		for _, g := range p.bySource(tok) {
			data, err := stableRead(g.source)
			if err != nil {
				if !os.IsNotExist(err) && !strings.Contains(err.Error(), "wird noch geschrieben") {
					c.message("Datei noch nicht lesbar. Erst nach /reload oder Logout verfügbar.")
				}
				continue
			}
			digest := hash(data)
			c.mu.Lock()
			last := c.lastHashes[g.source]
			c.mu.Unlock()
			if digest == last {
				unchanged = append(unchanged, g)
				continue
			}
			states := []ingameState{}
			allOK := true
			for _, cn := range g.conns {
				result, err := uploadWithResult(ctx, cn.p, cn.token, data)
				if err != nil {
					allOK = false
					c.message(fmt.Sprintf("Gilde %d: Upload nicht bestätigt: %s", cn.p.GuildID, briefError(err)))
					continue
				}
				if result.State != nil {
					st := *result.State
					st.Server, st.GuildID = cn.p.Server, cn.p.GuildID
					states = append(states, st)
				}
				c.report(result)
			}
			c.mu.Lock()
			// Prevent stale result from overwriting a newly saved profile.
			if allOK && c.profile.Server == p.Server && c.profile.GuildID == p.GuildID && c.profile.Source == p.Source && c.token == tok && len(c.profile.Extra) == len(p.Extra) {
				if c.lastHashes == nil {
					c.lastHashes = map[string]string{}
				}
				c.lastHashes[g.source] = digest
				c.lastUploaded = time.Now().Format("02.01.2006 15:04:05")
			}
			c.mu.Unlock()
			if len(states) > 0 {
				merged := mergeStates(states)
				c.mu.Lock()
				c.lastOutbox, c.lastManage = merged.Outbox, merged.ManageResults
				c.mu.Unlock()
				c.applyState(g.source, merged)
			}
		}
		if len(unchanged) > 0 {
			c.refreshState(ctx, unchanged)
		}
	}
}

// claimTick keeps the public key in the addon and watches for confirmations.
func (c *controller) claimTick(ctx context.Context) {
	c.mu.Lock()
	if time.Now().Before(c.nextClaim) {
		c.mu.Unlock()
		return
	}
	c.nextClaim = time.Now().Add(20 * time.Second)
	key := c.claimKey
	c.mu.Unlock()
	if key == nil {
		k, err := claimKey()
		if err != nil {
			return
		}
		key = k
		c.mu.Lock()
		c.claimKey = k
		c.mu.Unlock()
	}
	writeClaimFiles(key.Public().(ed25519.PublicKey))
	open := []joinOffer{}
	for _, o := range findOffers() {
		if !c.connected(o.Server, o.Guild) {
			open = append(open, o)
		}
	}
	c.mu.Lock()
	c.offers = open
	if c.notified == nil {
		c.notified, c.autoRetry = map[string]bool{}, map[string]bool{}
	}
	fresh, retry := false, []joinOffer{}
	for _, o := range open {
		if !c.notified[o.key()] {
			c.notified[o.key()], fresh = true, true
		}
		if c.autoRetry[o.key()] && time.Now().Minute()%2 == 0 {
			retry = append(retry, o)
		}
	}
	c.mu.Unlock()
	if fresh {
		c.message("Im Spiel bestätigt: bitte hier auf „Verbinden“ klicken.")
		browser(c.base + "/ui/" + c.uiKey)
	}
	for _, o := range retry {
		_, _ = c.connectOffer(ctx, o)
	}
}

func (c *controller) connected(server string, guild int64) bool {
	c.mu.Lock()
	defer c.mu.Unlock()
	if c.token != "" && c.profile.Server == server && c.profile.GuildID == guild {
		return true
	}
	for _, l := range c.profile.Extra {
		if l.Server == server && l.GuildID == guild {
			return true
		}
	}
	return false
}

// connectOffer redeems a confirmation and stores the new connection(s).
func (c *controller) connectOffer(ctx context.Context, o joinOffer) (string, error) {
	c.mu.Lock()
	key := c.claimKey
	c.mu.Unlock()
	if key == nil {
		return "", errors.New("Schlüssel noch nicht bereit")
	}
	grants, err := redeemOffer(ctx, key, o)
	if err != nil {
		if errors.Is(err, errNotYetConfirmed) {
			c.mu.Lock()
			c.autoRetry[o.key()] = true
			c.mu.Unlock()
		}
		return "", err
	}
	names := []string{}
	for _, g := range grants {
		if err := c.addConnection(o.Server, g.GuildID, g.Token, o.Source); err != nil {
			return "", err
		}
		names = append(names, g.Guild)
		if strings.HasPrefix(g.Welcome, o.Server+"/#welcome=") {
			browser(g.Welcome)
		}
	}
	c.mu.Lock()
	delete(c.autoRetry, o.key())
	c.mu.Unlock()
	msg := "Verbunden mit " + strings.Join(names, ", ") + ". Bestellungen im Spiel sind jetzt aktiv."
	c.message(msg)
	return msg, nil
}

func (c *controller) addConnection(server string, guild int64, token, source string) error {
	c.mu.Lock()
	defer c.mu.Unlock()
	if c.token == "" || (c.profile.Server == server && c.profile.GuildID == guild) {
		p := c.profile
		p.Server, p.GuildID = server, guild
		if p.Source == "" {
			p.Source = source
		}
		if p.IntervalSeconds == 0 {
			p.IntervalSeconds = 15
		}
		if err := saveProfile(p, token); err != nil {
			return err
		}
		c.profile, c.token, c.active, c.next, c.lastHashes = p, token, true, time.Time{}, nil
		return nil
	}
	sealed, err := protectNamed(linkAccount(server, guild), token)
	if err != nil {
		return err
	}
	base := c.profile
	extra := []Link{}
	for _, l := range base.Extra {
		if !(l.Server == server && l.GuildID == guild) {
			extra = append(extra, l)
		}
	}
	link := Link{Server: server, GuildID: guild, EncryptedToken: sealed}
	if source != "" && !strings.EqualFold(filepath.Clean(source), filepath.Clean(base.Source)) {
		link.Source = source
	}
	base.Extra = append(extra, link)
	if err := saveProfile(base, c.token); err != nil {
		return err
	}
	c.profile, c.lastHashes, c.next = base, nil, time.Time{}
	return nil
}

// withExtra keeps the further guild connections when the main one is re-saved.
func withExtra(p Profile, c *controller) Profile {
	c.mu.Lock()
	defer c.mu.Unlock()
	p.Extra = c.profile.Extra
	return p
}

func (c *controller) report(result uploadOutcome) {
	if result.OrdersOnly {
		c.message("Bestellungen übertragen. Status erscheint ingame nach /reload oder dem nächsten Login.")
		return
	}
	switch result.AutoStatus {
	case "reconciled":
		c.message("Inventur #" + result.ID + " automatisch serverseitig abgeglichen.")
	case "already_reconciled":
		c.message("Inventur #" + result.ID + " war bereits bestätigt (keine erneute Buchung).")
	case "review_required":
		c.message("Inventur #" + result.ID + " hochgeladen; Sicherheitsprüfung erfordert manuelle Kontrolle: " + result.AutoReason)
	default:
		c.message("Inventur #" + result.ID + " hochgeladen (zur Prüfung vorgemerkt, noch keine Buchung).")
	}
}

// refreshState polls the own order status every five minutes so that
// approvals and issues show up in game after the next /reload.
func (c *controller) refreshState(ctx context.Context, groups []sourceGroup) {
	c.mu.Lock()
	if time.Now().Before(c.nextState) {
		c.mu.Unlock()
		return
	}
	c.nextState = time.Now().Add(2 * time.Minute)
	c.mu.Unlock()
	for _, g := range groups {
		states := []ingameState{}
		for _, cn := range g.conns {
			if st, err := fetchState(ctx, cn.p, cn.token); err == nil {
				st.Server, st.GuildID = cn.p.Server, cn.p.GuildID
				states = append(states, st)
			}
		}
		if len(states) == 0 {
			continue
		}
		st := mergeStates(states)
		c.mu.Lock()
		if st.Outbox == nil {
			st.Outbox = map[string]outboxResult{}
		}
		for k, v := range c.lastOutbox {
			if _, ok := st.Outbox[k]; !ok {
				st.Outbox[k] = v
			}
		}
		if st.ManageResults == nil {
			st.ManageResults = map[string]manageResult{}
		}
		for k, v := range c.lastManage {
			if _, ok := st.ManageResults[k]; !ok {
				st.ManageResults[k] = v
			}
		}
		c.mu.Unlock()
		c.applyState(g.source, st)
	}
}

func (c *controller) applyState(source string, st ingameState) {
	changed, err := writeServerState(source, st)
	if err != nil {
		c.message("Bestellstatus nicht ins Addon geschrieben: " + briefError(err))
	} else if changed {
		c.message("Bestellstatus aktualisiert (sichtbar ingame nach /reload).")
	}
}

func briefError(err error) string {
	s := err.Error()
	if strings.Contains(s, "HTTP 401") {
		return "Token ungültig/abgelaufen oder Gilde nicht berechtigt"
	}
	if strings.Contains(s, "GAME_VERSION_MISMATCH") {
		return "Die Datei stammt aus einer anderen WoW-Version als die Gilde. Bitte beim Hinzufügen die Banklord.lua der passenden Installation wählen"
	}
	if strings.Contains(s, "HTTP 409") {
		return "Konflikt mit bestehender Inventur"
	}
	if strings.Contains(s, "HTTP 422") {
		return "Inventurdaten vom Server abgelehnt"
	}
	if strings.Contains(s, "HTTP 429") {
		return "Uploadlimit erreicht"
	}
	if strings.Contains(s, "HTTP 500") {
		return "Serverfehler (Serverprotokoll prüfen)"
	}
	// Do not print errors containing full URLs or credentials in logs/UI.
	return "HTTPS/Server/Datei prüfen (" + strconv.Itoa(len(s)) + " Zeichen Fehlerdetails verborgen)"
}
func browser(url string) {
	if runtime.GOOS == "windows" {
		_ = exec.Command("rundll32", "url.dll,FileProtocolHandler", url).Start()
		return
	}
	if runtime.GOOS == "darwin" {
		_ = exec.Command("open", url).Start()
		return
	}
	_ = exec.Command("xdg-open", url).Start()
}
func secureKey() (string, error) {
	buf := make([]byte, 24)
	if _, err := rand.Read(buf); err != nil {
		return "", err
	}
	return hex.EncodeToString(buf), nil
}

// Main runs the companion: local browser UI, uploads and in-game status.
func Main() {
	if existing, err := existingSession(); err == nil {
		browser(existing)
		return
	} else if errors.Is(err, errOldCompanionRunning) {
		alertOldCompanion()
		return
	}
	secret, err := secureKey()
	if err != nil {
		log.Fatal(err)
	}
	csrf, err := secureKey()
	if err != nil {
		log.Fatal(err)
	}
	c := &controller{uiKey: secret, csrfToken: csrf, status: "Einrichtung erforderlich"}
	p, tok, err := loadProfile()
	if err == nil {
		c.profile = p
		c.token = tok
		c.active = true
		c.status = "Synchronisierung aktiv (nach Speicherung durch WoW)"
	}
	listener, err := net.Listen("tcp4", "127.0.0.1:0")
	if err != nil {
		log.Fatal(err)
	}
	c.base = "http://" + listener.Addr().String()
	mux := http.NewServeMux()
	mux.HandleFunc("/ui/"+secret, c.handle)
	mux.HandleFunc("/", func(w http.ResponseWriter, r *http.Request) { http.NotFound(w, r) })
	c.server = &http.Server{Handler: mux, ReadHeaderTimeout: 5 * time.Second, ReadTimeout: 12 * time.Second, WriteTimeout: 30 * time.Second, IdleTimeout: 15 * time.Second}
	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()
	go c.run(ctx)
	go func() {
		if e := c.server.Serve(listener); e != nil && e != http.ErrServerClosed {
			log.Print("Lokale Oberfläche nicht erreichbar")
		}
	}()
	u := c.base + "/ui/" + secret
	if err := storeSession(u); err != nil {
		log.Print("Warnung: lokale Wiederoeffnung nicht hinterlegt")
	}
	fmt.Println("Banklord Companion: Browseroberfläche " + u)
	// The setup opens the page itself in its own tab after installing.
	if os.Getenv("BANKLORD_NO_BROWSER") != "1" {
		browser(u)
	}
	<-ctx.Done()
}

var page = template.Must(template.New("page").Parse(`<!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Banklord Companion</title><link rel="icon" href="data:image/png;base64,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"><style>
:root{color-scheme:dark;font-family:system-ui,Arial,sans-serif}*{box-sizing:border-box}body{margin:0;background:#111927;color:#eef2fc}main{max-width:790px;margin:3em auto;padding:0 1.3em}h1{font-size:1.65rem}p,small{color:#b7c4d9;line-height:1.5}article{border:1px solid #344258;border-radius:12px;background:#1b273a;padding:1.3em;margin:1em 0}label{display:block;margin:.8em 0 .35em;font-weight:600}input,select{width:100%;background:#101b2a;color:white;border:1px solid #526784;border-radius:7px;padding:.7em;font:inherit}button{margin:.8em .5em .3em 0;padding:.7em 1em;border:0;border-radius:8px;background:#4b90f9;color:#061326;font-weight:700;cursor:pointer}button.secondary{background:#425673;color:white}.status{font-weight:700;color:#9bdfc2}.info{color:#b7c4d9}.error{color:#ffb3b3}code{color:#99d5f8}.warning{border-left:3px solid #e4b267;padding-left:.75em}ul{padding-left:1.5em;line-height:1.8}</style></head><body><main><h1>Banklord Companion <small>{{.Version}}</small></h1>
<p>Einmal einrichten, danach werden neue <strong>gespeicherte</strong> Bankscans automatisch über verschlüsseltes HTTPS übertragen. Während des Spielens schreibt WoW die Datei regulär erst bei <code>/reload</code> oder Logout.</p>
{{if .Offers}}<article><h2>Freischaltung im Spiel</h2>{{range $i, $e := .Offers}}<form method="post"><input type="hidden" name="csrf" value="{{$.CSRF}}"><input type="hidden" name="index" value="{{$i}}"><p>{{$e}}</p><p><small>Verbinde nur, wenn du diese Website-Adresse kennst.</small></p><button name="action" value="claim">Verbinden</button></form>{{end}}</article>{{end}}
<article><h2>Status</h2><p class="status">{{.Status}}</p>{{if .Last}}<p>Letzter bestätigter Upload: {{.Last}}</p>{{end}}<p>{{if .Active}}Überwachung aktiv{{else if .Configured}}Überwachung pausiert{{else}}Noch nicht verbunden: WoW starten, einloggen und <code>/reload</code> – sobald ein Bankverwalter online ist, erscheint hier „Freischaltung im Spiel“. Alternativ unten einen Verbindungscode einfügen.{{end}} · Scans von Bankverwaltern, Offizieren und Admins übernimmt die Website automatisch, wenn ein Admin den automatischen Abgleich eingeschaltet hat; sonst warten sie unter Inventur auf Bestätigung.</p><form method="post"><input type="hidden" name="csrf" value="{{.CSRF}}"><button name="action" value="refresh" class="secondary">Status aktualisieren</button>{{if .Configured}}<button name="action" value="toggle" class="secondary">{{if .Active}}Pause{{else}}Fortsetzen{{end}}</button>{{end}}<button name="action" value="exit" class="secondary">Companion beenden</button></form><ul>{{range .Messages}}<li>{{.}}</li>{{end}}</ul></article>
<article><h2>Einrichtung</h2><form method="post" autocomplete="off"><input type="hidden" name="csrf" value="{{.CSRF}}">
<label for="connect"><strong>Verbindungscode</strong> (auf der Website unter Konto erzeugen und hier einfügen – füllt Server, Gilde und Schlüssel)</label><input name="connect" id="connect" type="password" placeholder="BL1C:…" spellcheck="false" autocomplete="off">
<details{{if not .Server}}{{else}} open{{end}}><summary>Manuell eintragen</summary>
<label for="server">Adresse der Website (HTTPS)</label><input name="server" id="server" type="url" placeholder="https://banklord.mgc-nom.net" value="{{.Server}}">
<label for="guild">Gilden-ID aus der Website</label><input name="guild" id="guild" type="number" min="0" max="2147483647" value="{{.Guild}}">
<label for="source">Banklord.lua – vollständiger Dateipfad</label><input name="source" id="source" value="{{.Source}}" placeholder="C:\...\_classic_\WTF\Account\...\SavedVariables\Banklord.lua">
{{if .Files}}<label>Gefundene Dateien als Hilfe</label><select name="detected"><option value="">Gefundene Datei auswählen (überschreibt den Textpfad)</option>{{range .Files}}<option value="{{.}}">{{.}}</option>{{end}}</select>{{end}}
<label for="token">Bridge-Token (im Web-Adminbereich einmalig erzeugen)</label><input name="token" id="token" type="password" placeholder="{{if .Configured}}Leer lassen, um bisherigen Schlüssel zu behalten{{else}}bl1_...{{end}}" spellcheck="false" autocomplete="off">
</details>
<label for="interval">Datei-Überprüfung in Sekunden</label><input name="interval" id="interval" type="number" min="10" max="600" value="{{.Interval}}" required>
<button name="action" value="save">Konfiguration speichern und starten</button><button name="action" value="test" class="secondary">Server und Token prüfen</button></form>
{{if .Result}}<p class="{{if .HasError}}error{{else}}status{{end}}">{{.Result}}</p>{{end}}
<p class="warning"><strong>Sicherheit:</strong> Token liegt lokal mittels Windows-DPAPI verschlüsselt unter deinem Windows-Benutzerprofil; niemals im WoW-Addon. HTTPS-Zertifikate werden geprüft, Redirects blockiert. Kein direkter DB-Zugriff.</p></article>
<article><h2>Weitere Gilden auf diesem PC</h2><p>Spielst du Charaktere in mehreren Gilden? Für jede weitere Gilde auf deren Website unter <b>Konto → Bestellen im Spiel</b> einen Verbindungscode erzeugen und hier einfügen. Banklord ordnet Bestellungen automatisch der Gilde des eingeloggten Charakters zu. Spielt die Gilde in einer anderen WoW-Version (z. B. Retail statt Classic), wähle die Banklord.lua dieser Installation.</p>
{{range $i, $e := .Extra}}<form method="post"><input type="hidden" name="csrf" value="{{$.CSRF}}"><input type="hidden" name="index" value="{{$i}}"><p>{{$e}} <button name="action" value="removeguild" class="secondary">Entfernen</button></p></form>{{end}}
<form method="post" autocomplete="off"><input type="hidden" name="csrf" value="{{.CSRF}}"><label for="extra">Verbindungscode einer weiteren Gilde</label><input name="extra" id="extra" type="password" placeholder="BL1C:…" spellcheck="false" autocomplete="off">{{if gt (len .Files) 1}}<label for="extrasource">WoW-Installation dieser Gilde</label><select name="extrasource" id="extrasource"><option value="">Wie die Hauptverbindung</option>{{range .Files}}<option value="{{.}}">{{.}}</option>{{end}}</select>{{end}}<button name="action" value="addguild">Gilde hinzufügen</button></form></article>
<p>Hinweis: Dieses Programm verbindet die <strong>gespeicherte</strong> Inventur mit der Website, nicht das laufende Addon in Echtzeit. Im Browser kannst du Bestellungen unmittelbar verwalten.</p></main></body></html>`))

type view struct {
	Status, Last, Server, Guild, Source, Interval, Result, CSRF string
	Files, Messages, Extra, Offers                              []string
	Active, Configured, HasError                                bool
	Version                                                     string
}

func (c *controller) handle(w http.ResponseWriter, r *http.Request) {
	if r.Host != strings.TrimPrefix(c.base, "http://") {
		http.Error(w, "Host nicht erlaubt", 403)
		return
	}
	w.Header().Set("X-BL-Companion-Version", companionVersion)
	w.Header().Set("Cache-Control", "no-store")
	w.Header().Set("Referrer-Policy", "no-referrer")
	w.Header().Set("X-Frame-Options", "DENY")
	w.Header().Set("X-Content-Type-Options", "nosniff")
	w.Header().Set("Content-Security-Policy", "default-src 'none'; img-src data:; style-src 'unsafe-inline'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'")
	if r.Method != http.MethodGet && r.Method != http.MethodPost {
		http.Error(w, "Nicht erlaubt", 405)
		return
	}
	var result string
	var failure bool
	var draft *Profile
	if r.Method == http.MethodPost {
		// Browsers and privacy software can send inconsistent Origin and
		// Sec-Fetch-Site headers for loopback traffic. They are not treated as
		// authentication or as a blocking signal here. Instead, every POST
		// MUST supply the independent, unpredictable, per-process CSRF token
		// from this page. The handler is exposed ONLY on 127.0.0.1, protects
		// against DNS rebinding via an exact Host check, and is reachable only
		// at a cryptographically random per-run URL. No permissive CORS headers
		// are sent, so other websites cannot read the CSRF token.
		mediaType, _, err := mime.ParseMediaType(r.Header.Get("Content-Type"))
		if err != nil || mediaType != "application/x-www-form-urlencoded" {
			http.Error(w, "Ungültiger Formulartyp", http.StatusUnsupportedMediaType)
			return
		}
		r.Body = http.MaxBytesReader(w, r.Body, 8192)
		if err := r.ParseForm(); err != nil {
			http.Error(w, "Ungültige Eingabe", http.StatusBadRequest)
			return
		}
		suppliedCSRF := r.PostForm.Get("csrf")
		if c.csrfToken == "" || subtle.ConstantTimeCompare([]byte(suppliedCSRF), []byte(c.csrfToken)) != 1 {
			http.Error(w, "Formularsitzung ungültig – Seite neu laden", http.StatusForbidden)
			return
		}
		action := r.Form.Get("action")
		switch action {
		case "exit":
			w.Header().Set("Content-Type", "text/plain; charset=utf-8")
			w.Write([]byte("Companion beendet. Du kannst diesen Tab schließen."))
			removeSession()
			go func() { time.Sleep(100 * time.Millisecond); os.Exit(0) }()
			return
		case "toggle":
			c.mu.Lock()
			c.active = !c.active
			c.next = time.Time{}
			c.mu.Unlock()
		case "save", "test":
			c.mu.Lock()
			token := c.token
			c.mu.Unlock()
			p := Profile{Server: strings.TrimRight(strings.TrimSpace(r.Form.Get("server")), "/"), Source: strings.TrimSpace(r.Form.Get("source"))}
			var code connectCode
			var codeErr error
			if raw := strings.TrimSpace(r.Form.Get("connect")); raw != "" {
				code, codeErr = parseConnectCode(raw)
				if codeErr == nil {
					p.Server = code.Server
				}
			}
			if p.Source == "" {
				if found := discover(); len(found) == 1 {
					p.Source = found[0]
				}
			}
			if choice := r.Form.Get("detected"); choice != "" {
				for _, allowed := range discover() {
					if choice == allowed {
						p.Source = choice
						break
					}
				}
			}
			draft = &p
			p.GuildID, _ = strconv.ParseInt(r.Form.Get("guild"), 10, 64)
			p.IntervalSeconds, _ = strconv.Atoi(r.Form.Get("interval"))
			if supplied := strings.TrimSpace(r.Form.Get("token")); supplied != "" {
				token = supplied
			}
			if code.Token != "" {
				p.GuildID, token = code.Guild, code.Token
			}
			if codeErr != nil {
				result = codeErr.Error()
				failure = true
			} else if err := p.validate(); err != nil {
				result = err.Error()
				failure = true
			} else if !tokenPattern.MatchString(token) {
				result = "Ein gültiges Bridge-Token von der Website fehlt."
				failure = true
			} else if action == "test" {
				ctx, cancel := context.WithTimeout(r.Context(), 25*time.Second)
				err := probe(ctx, p, token)
				cancel()
				if err != nil {
					result = "Verbindung fehlgeschlagen: " + briefError(err)
					failure = true
				} else {
					c.mu.Lock()
					c.token = token
					c.mu.Unlock()
					result = "HTTPS, Gilde und Bridge-Token sind gültig. Jetzt Einstellungen speichern und starten."
				}
			} else {
				// withExtra takes c.mu itself: merge before locking (a nested Lock deadlocked "save").
				merged := withExtra(p, c)
				if err := saveProfile(merged, token); err != nil {
					result = "Speichern fehlgeschlagen: " + err.Error()
					failure = true
				} else {
					c.mu.Lock()
					c.profile = merged
					c.token = token
					c.active = true
					c.next = time.Time{}
					c.lastHashes = nil
					c.status = "Überwachung gestartet"
					c.mu.Unlock()
					result = "Gespeichert. Automatischer Upload nach der nächsten Dateispeicherung gestartet."
				}
			}
			// No token is ever returned to the browser in rendered HTML.
		case "addguild":
			code, err := parseConnectCode(r.Form.Get("extra"))
			c.mu.Lock()
			base, primary := c.profile, c.token
			c.mu.Unlock()
			switch {
			case err != nil:
				result, failure = err.Error(), true
			case primary == "":
				result, failure = "Zuerst die erste Gilde einrichten.", true
			default:
				q := base
				q.Server, q.GuildID, q.Extra = code.Server, code.Guild, nil
				ctx, cancel := context.WithTimeout(r.Context(), 25*time.Second)
				err = q.validate()
				if err == nil {
					err = probe(ctx, q, code.Token)
				}
				cancel()
				sealed := ""
				if err == nil {
					sealed, err = protectNamed(linkAccount(q.Server, q.GuildID), code.Token)
				}
				if err != nil {
					result, failure = "Gilde nicht verbunden: "+briefError(err), true
					break
				}
				extra := []Link{}
				for _, l := range base.Extra {
					if !(l.Server == q.Server && l.GuildID == q.GuildID) {
						extra = append(extra, l)
					}
				}
				if q.Server == base.Server && q.GuildID == base.GuildID {
					result, failure = "Diese Gilde ist bereits die Hauptverbindung.", true
					break
				}
				link := Link{Server: q.Server, GuildID: q.GuildID, EncryptedToken: sealed}
				if chosen := r.Form.Get("extrasource"); chosen != "" && !strings.EqualFold(chosen, base.Source) {
					for _, allowed := range discover() {
						if chosen == allowed {
							link.Source = chosen
						}
					}
				}
				base.Extra = append(extra, link)
				if err := saveProfile(base, primary); err != nil {
					result, failure = "Speichern fehlgeschlagen: "+err.Error(), true
					break
				}
				c.mu.Lock()
				c.profile, c.lastHashes, c.next = base, nil, time.Time{}
				c.mu.Unlock()
				result = fmt.Sprintf("Gilde %d verbunden. Bestellungen und Status laufen jetzt für alle Gilden.", q.GuildID)
			}
		case "removeguild":
			idx, _ := strconv.Atoi(r.Form.Get("index"))
			c.mu.Lock()
			base, primary := c.profile, c.token
			c.mu.Unlock()
			if idx >= 0 && idx < len(base.Extra) && primary != "" {
				base.Extra = append(append([]Link{}, base.Extra[:idx]...), base.Extra[idx+1:]...)
				if err := saveProfile(base, primary); err == nil {
					c.mu.Lock()
					c.profile = base
					c.mu.Unlock()
					result = "Verbindung entfernt."
				}
			}
		case "claim":
			idx, _ := strconv.Atoi(r.Form.Get("index"))
			c.mu.Lock()
			offers := append([]joinOffer(nil), c.offers...)
			c.mu.Unlock()
			if idx < 0 || idx >= len(offers) {
				result, failure = "Bestätigung nicht mehr vorhanden.", true
				break
			}
			ctx, cancel := context.WithTimeout(r.Context(), 30*time.Second)
			msg, err := c.connectOffer(ctx, offers[idx])
			cancel()
			if err != nil {
				result, failure = briefError(err), true
			} else {
				result = msg
			}
		case "refresh":
		default:
			http.Error(w, "Aktion unbekannt", 400)
			return
		}
	}
	c.mu.Lock()
	p := c.profile
	data := view{Version: companionVersion, CSRF: c.csrfToken, Status: c.status, Last: c.lastUploaded, Server: p.Server, Guild: strconv.FormatInt(p.GuildID, 10), Source: p.Source, Interval: strconv.Itoa(p.IntervalSeconds), Result: result, HasError: failure, Messages: append([]string(nil), c.messages...), Active: c.active, Configured: c.token != ""}
	for _, l := range p.Extra {
		src := l.Source
		if src == "" {
			src = p.Source
		}
		data.Extra = append(data.Extra, fmt.Sprintf("%s · Gilde %d · %s", l.Server, l.GuildID, clientLabel(src)))
	}
	for _, o := range c.offers {
		by := o.By
		if by == "" {
			by = "Ein Bankverwalter"
		}
		data.Offers = append(data.Offers, fmt.Sprintf("%s hat dich im Spiel bestätigt. Website: %s (Gilde %d)", by, o.Server, o.Guild))
	}
	c.mu.Unlock()
	if data.Guild == "0" {
		data.Guild = "1"
	}
	if data.Interval == "0" {
		data.Interval = "15"
	}
	if draft != nil {
		data.Server = draft.Server
		data.Guild = strconv.FormatInt(draft.GuildID, 10)
		data.Source = draft.Source
		data.Interval = strconv.Itoa(draft.IntervalSeconds)
	}
	data.Files = discover()
	if data.Source == "" && len(data.Files) == 1 {
		data.Source = data.Files[0]
	}
	w.Header().Set("Content-Type", "text/html; charset=utf-8")
	if err := page.Execute(w, data); err != nil {
		log.Print("Templatefehler")
	}
}
