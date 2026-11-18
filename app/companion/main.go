package companion

import (
	"context"
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
	"runtime"
	"strconv"
	"strings"
	"sync"
	"time"
)

const companionVersion = "0.7.0"

type controller struct {
	mu           sync.Mutex
	profile      Profile
	token        string
	active       bool
	next         time.Time
	lastHash     string
	nextState    time.Time
	nextBeat     time.Time
	lastOutbox   map[string]outboxResult
	lastManage   map[string]manageResult
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
		c.mu.Lock()
		if !c.active || time.Now().Before(c.next) {
			c.mu.Unlock()
			continue
		}
		p, tok := c.profile, c.token
		c.next = time.Now().Add(time.Duration(p.IntervalSeconds) * time.Second)
		last := c.lastHash
		c.mu.Unlock()
		data, err := stableRead(p.Source)
		if err != nil {
			if !os.IsNotExist(err) && !strings.Contains(err.Error(), "wird noch geschrieben") {
				c.message("Datei noch nicht lesbar. Erst nach /reload oder Logout verfügbar.")
			}
			continue
		}
		digest := hash(data)
		if time.Now().After(c.nextBeat) {
			c.nextBeat = time.Now().Add(30 * time.Minute)
			go heartbeat(ctx, p, tok)
		}
		if digest == last {
			c.refreshState(ctx, p, tok)
			continue
		}
		result, err := uploadWithResult(ctx, p, tok, data)
		if err != nil {
			c.message("Upload nicht bestätigt: " + briefError(err))
			continue
		}
		c.mu.Lock()
		// Prevent stale result from overwriting a newly saved profile.
		if c.profile.Server == p.Server && c.profile.GuildID == p.GuildID && c.profile.Source == p.Source && c.token == tok {
			c.lastHash = digest
			c.lastUploaded = time.Now().Format("02.01.2006 15:04:05")
		}
		c.mu.Unlock()
		if result.State != nil {
			c.mu.Lock()
			c.lastOutbox = result.State.Outbox
			c.lastManage = result.State.ManageResults
			c.mu.Unlock()
			c.applyState(p, *result.State)
		}
		if result.OrdersOnly {
			c.message("Bestellungen übertragen. Status erscheint ingame nach /reload oder dem nächsten Login.")
			continue
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
}

// refreshState polls the own order status every five minutes so that
// approvals and issues show up in game after the next /reload.
func (c *controller) refreshState(ctx context.Context, p Profile, tok string) {
	c.mu.Lock()
	if time.Now().Before(c.nextState) {
		c.mu.Unlock()
		return
	}
	c.nextState = time.Now().Add(2 * time.Minute)
	c.mu.Unlock()
	st, err := fetchState(ctx, p, tok)
	if err != nil {
		return
	}
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
	c.applyState(p, st)
}

func (c *controller) applyState(p Profile, st ingameState) {
	changed, err := writeServerState(p.Source, st)
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
	browser(u)
	<-ctx.Done()
}

var page = template.Must(template.New("page").Parse(`<!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Banklord Companion</title><style>
:root{color-scheme:dark;font-family:system-ui,Arial,sans-serif}*{box-sizing:border-box}body{margin:0;background:#111927;color:#eef2fc}main{max-width:790px;margin:3em auto;padding:0 1.3em}h1{font-size:1.65rem}p,small{color:#b7c4d9;line-height:1.5}article{border:1px solid #344258;border-radius:12px;background:#1b273a;padding:1.3em;margin:1em 0}label{display:block;margin:.8em 0 .35em;font-weight:600}input,select{width:100%;background:#101b2a;color:white;border:1px solid #526784;border-radius:7px;padding:.7em;font:inherit}button{margin:.8em .5em .3em 0;padding:.7em 1em;border:0;border-radius:8px;background:#4b90f9;color:#061326;font-weight:700;cursor:pointer}button.secondary{background:#425673;color:white}.status{font-weight:700;color:#9bdfc2}.info{color:#b7c4d9}.error{color:#ffb3b3}code{color:#99d5f8}.warning{border-left:3px solid #e4b267;padding-left:.75em}ul{padding-left:1.5em;line-height:1.8}</style></head><body><main><h1>Banklord Companion <small>0.5.0</small></h1>
<p>Einmal einrichten, danach werden neue <strong>gespeicherte</strong> Bankscans automatisch über verschlüsseltes HTTPS übertragen. Während des Spielens schreibt WoW die Datei regulär erst bei <code>/reload</code> oder Logout.</p>
<article><h2>Status</h2><p class="status">{{.Status}}</p>{{if .Last}}<p>Letzter bestätigter Upload: {{.Last}}</p>{{end}}<p>{{if .Active}}Überwachung aktiv{{else}}Überwachung pausiert oder noch nicht eingerichtet{{end}} · Ohne ausdrücklich eingerichteten Auto-Token bleiben Scans zur Prüfung vorgemerkt. Bei aktivierter Automatik werden geeignete Scans automatisch abgeglichen; Konflikte erfordern manuelle Prüfung.</p><form method="post"><input type="hidden" name="csrf" value="{{.CSRF}}"><button name="action" value="refresh" class="secondary">Status aktualisieren</button>{{if .Configured}}<button name="action" value="toggle" class="secondary">{{if .Active}}Pause{{else}}Fortsetzen{{end}}</button>{{end}}<button name="action" value="exit" class="secondary">Companion beenden</button></form><ul>{{range .Messages}}<li>{{.}}</li>{{end}}</ul></article>
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
<p>Hinweis: Dieses Programm verbindet die <strong>gespeicherte</strong> Inventur mit der Website, nicht das laufende Addon in Echtzeit. Im Browser kannst du Bestellungen unmittelbar verwalten.</p></main></body></html>`))

type view struct {
	Status, Last, Server, Guild, Source, Interval, Result, CSRF string
	Files, Messages                                             []string
	Active, Configured, HasError                                bool
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
	w.Header().Set("Content-Security-Policy", "default-src 'none'; style-src 'unsafe-inline'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'")
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
			} else if err := saveProfile(p, token); err != nil {
				result = "Speichern fehlgeschlagen: " + err.Error()
				failure = true
			} else {
				c.mu.Lock()
				c.profile = p
				c.token = token
				c.active = true
				c.next = time.Time{}
				c.lastHash = ""
				c.status = "Überwachung gestartet"
				c.mu.Unlock()
				result = "Gespeichert. Automatischer Upload nach der nächsten Dateispeicherung gestartet."
			}
			// No token is ever returned to the browser in rendered HTML.
		case "refresh":
		default:
			http.Error(w, "Aktion unbekannt", 400)
			return
		}
	}
	c.mu.Lock()
	p := c.profile
	data := view{CSRF: c.csrfToken, Status: c.status, Last: c.lastUploaded, Server: p.Server, Guild: strconv.FormatInt(p.GuildID, 10), Source: p.Source, Interval: strconv.Itoa(p.IntervalSeconds), Result: result, HasError: failure, Messages: append([]string(nil), c.messages...), Active: c.active, Configured: c.token != ""}
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
