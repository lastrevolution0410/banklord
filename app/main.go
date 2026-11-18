package main

import (
	"banklord/companion"
	"context"
	"crypto/rand"
	"encoding/hex"
	"fmt"
	"html/template"
	"log"
	"net"
	"net/http"
	"os"
	"os/exec"
	"runtime"
	"strings"
	"sync"
	"time"
)


var page = template.Must(template.New("wizard").Parse(`<!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="referrer" content="no-referrer">{{if .Scanning}}<meta http-equiv="refresh" content="3">{{end}}<title>Banklord – Verwaltung</title><style>
:root{color-scheme:dark;font-family:system-ui,Segoe UI,Arial,sans-serif}body{background:#101826;color:#ecf2f8;margin:0;padding:25px}main{max-width:900px;margin:0 auto}h1{font-size:1.7rem;margin:.4rem 0}p{color:#bfd0e1;line-height:1.5}.panel{background:#1b283b;border:1px solid #34455e;border-radius:13px;padding:22px;margin:15px 0}.ok{color:#a0ebc1}.warn{color:#f2d19b}.danger{color:#ffa7a7}label{display:block;margin:12px 0;line-height:1.45}input[type=text]{box-sizing:border-box;width:100%;font-size:1rem;padding:11px;background:#0d1522;color:#fff;border:1px solid #5a708f;border-radius:8px}button{padding:11px 19px;background:#315fb8;color:#fff;border:0;border-radius:8px;cursor:pointer;font-size:1rem;margin:4px 7px 4px 0}button.secondary{background:#435065}button.danger{background:#993e47}button:disabled{background:#444;cursor:not-allowed}.client{padding:13px;margin:9px 0;background:#121d2b;border:1px solid #34455e;border-radius:9px}.muted{font-size:.88rem;color:#aec2d8}code{overflow-wrap:anywhere}footer{font-size:.8rem;color:#93a7bd}small{display:block;color:#b8c9dc;margin:6px 0}input[type=checkbox]{width:1rem;height:1rem}b{color:white}</style></head><body><main>
<h1>Banklord einrichten</h1><p>Setup {{.InstallerVersion}}. Ein Klick installiert oder aktualisiert Banklord in allen gefundenen WoW-Versionen. Eigene Einstellungen, andere Addons und gespeicherte Daten bleiben unberührt.</p>
{{if .Message}}<div class="panel {{if .IsError}}danger{{else}}ok{{end}}">{{.Message}}</div>{{end}}
<div class="panel">
{{if .Scanning}}<p class="warn">WoW wird gesucht … diese Seite aktualisiert sich selbst.</p>{{else}}
<form method="post" action="{{.Prefix}}/manage"><input type="hidden" name="token" value="{{.Token}}">
<h2>Gefundene WoW-Versionen</h2>
{{range .Installs}}<label class="client"><input type="checkbox" name="clients" value="{{.Path}}" checked> <b>{{.Edition}}</b> · Banklord: <b>{{.AddonCondition}}</b>{{if .AddonVersion}} ({{.AddonVersion}}){{end}}<br><small>{{.Reason}} · <code>{{.Path}}</code></small></label>{{else}}<p class="warn">Keine WoW-Installation gefunden. Unten unter „Erweitert“ den Ordner von Hand wählen.</p>{{end}}
<input type="hidden" name="companion" value="yes"><input type="hidden" name="autostart" value="yes"><input type="hidden" name="launch" value="yes"><input type="hidden" name="autoupdate" value="yes">
<p class="muted">Dazu werden der Companion (verbindet Spiel und Website) und automatische Updates eingerichtet. WoW muss dafür geschlossen sein.</p>
{{if .Installs}}<button name="action" value="auto" type="submit">Installieren / Aktualisieren</button>{{end}}
</form>
<form method="post" action="{{.Prefix}}/search"><input type="hidden" name="token" value="{{.Token}}"><button class="secondary">Erneut suchen</button></form>{{end}}
</div>
<div class="panel"><h2>Danach</h2><p>Auf der Website unter <b>Konto → Bestellen im Spiel</b> einen Verbindungscode erzeugen und im Companion-Fenster einfügen. Im Spiel öffnet <b>/banklord</b> das Addon.</p></div>
<details class="panel"><summary><b>Erweitert</b> – Ordner von Hand, Reparieren, Updates, Deinstallieren</summary>
<form method="post" action="{{.Prefix}}/browse"><input type="hidden" name="token" value="{{.Token}}"><button type="submit" class="secondary">Ordner durchsuchen …</button></form>
<form method="post" action="{{.Prefix}}/inspect"><input type="hidden" name="token" value="{{.Token}}"><label>WoW-Clientordner<input name="client" type="text" list="paths" value="{{.Selected}}" required placeholder="D:\World of Warcraft\_classic_"></label><datalist id="paths">{{range .Installs}}<option value="{{.Path}}">{{.Edition}}</option>{{end}}</datalist><button type="submit" class="secondary">Prüfen</button></form>
{{if .HasClient}}<div class="client"><b>{{.ClientEdition}}</b><br><code>{{.Selected}}</code><p>Addon: <b>{{.Addon.Condition}}</b> ({{if .Addon.InstalledVersion}}{{.Addon.InstalledVersion}}{{else}}–{{end}} / Paket {{.Addon.PackagedVersion}}) · Companion: <b>{{.Companion.Condition}}</b> ({{if .Companion.InstalledVersion}}{{.Companion.InstalledVersion}}{{else}}–{{end}} / Paket {{.Companion.PackagedVersion}})</p></div>{{end}}
<form method="post" action="{{.Prefix}}/manage"><input type="hidden" name="token" value="{{.Token}}"><input type="hidden" name="client" value="{{.Selected}}"><input type="hidden" name="companion" value="yes"><input type="hidden" name="autostart" value="yes"><input type="hidden" name="launch" value="yes"><input type="hidden" name="autoupdate" value="yes">
<button name="action" value="auto" class="secondary" type="submit">Nur diesen Ordner installieren</button> <button name="action" value="repair" class="secondary" type="submit">Reparieren</button></form>
<h3>Updates</h3><p class="muted">Updates sind signiert (Ed25519) und werden nach Spielende eingespielt.</p>
<form method="post" action="{{.Prefix}}/updates-check"><input type="hidden" name="token" value="{{.Token}}"><button type="submit" class="secondary">Online-Version prüfen</button></form>
<form method="post" action="{{.Prefix}}/updates-apply"><input type="hidden" name="token" value="{{.Token}}"><input type="hidden" name="client" value="{{.Selected}}"><button type="submit" class="secondary">Updates jetzt installieren</button></form>
<form method="post" action="{{.Prefix}}/launch-checked"><input type="hidden" name="token" value="{{.Token}}"><input type="hidden" name="client" value="{{.Selected}}"><button type="submit" class="secondary">WoW mit Updateprüfung starten</button></form>
<form method="post" action="{{.Prefix}}/updates-autostart"><input type="hidden" name="token" value="{{.Token}}"><button type="submit" name="enabled" value="yes" class="secondary">Automatische Updates an</button> <button type="submit" name="enabled" value="no" class="secondary">aus</button></form>
<h3 class="danger">Deinstallieren</h3>
<form method="post" action="{{.Prefix}}/manage"><input type="hidden" name="token" value="{{.Token}}"><input type="hidden" name="client" value="{{.Selected}}">
<label><input type="checkbox" name="removeCompanion" value="yes"> Auch Companion und Update-Dienst entfernen</label>
<label><input type="checkbox" name="confirmRemove" value="yes"> Ja, Banklord aus dem oben geprüften Ordner entfernen</label>
<button name="action" value="uninstall" class="danger" type="submit">Deinstallieren</button><p class="muted">Gespeicherte Daten (WTF/SavedVariables) bleiben erhalten.</p></form>
</details>
<footer>Lokaler Assistent auf 127.0.0.1 mit zufälliger Sitzungsadresse und CSRF-Schutz.</footer></main></body></html>`))

type viewData struct {
	Token, Prefix, Selected, Message, InstallerVersion, ClientEdition string
	IsError, Scanning, HasClient                                      bool
	Installs                                                          []Install
	Addon, Companion                                                  ComponentStatus
}
type wizard struct {
	mu                               sync.Mutex
	actionMu                         sync.Mutex
	token, prefix, selected, message string
	isError, scanning                bool
	installs                         []Install
}

func nonce() string {
	b := make([]byte, 32)
	if _, e := rand.Read(b); e != nil {
		panic(e)
	}
	return hex.EncodeToString(b)
}
func (w *wizard) secure(h http.HandlerFunc) http.HandlerFunc {
	return func(out http.ResponseWriter, r *http.Request) {
		out.Header().Set("Cache-Control", "no-store")
		out.Header().Set("X-Content-Type-Options", "nosniff")
		out.Header().Set("Referrer-Policy", "no-referrer")
		out.Header().Set("Content-Security-Policy", "default-src 'none'; style-src 'unsafe-inline'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'")
		if !strings.HasPrefix(r.Host, "127.0.0.1:") {
			http.Error(out, "Nur lokaler Zugriff", http.StatusForbidden)
			return
		}
		if r.Method == http.MethodPost {
			if e := r.ParseForm(); e != nil || r.PostForm.Get("token") != w.token {
				http.Error(out, "Sitzung ungültig", http.StatusForbidden)
				return
			}
		}
		h(out, r)
	}
}
func (w *wizard) notice(msg string, bad bool) {
	w.mu.Lock()
	defer w.mu.Unlock()
	w.message = msg
	w.isError = bad
}
func (w *wizard) render(out http.ResponseWriter, r *http.Request) {
	w.mu.Lock()
	data := viewData{Token: w.token, Prefix: w.prefix, Selected: w.selected, Message: w.message, IsError: w.isError, Scanning: w.scanning, Installs: append([]Install(nil), w.installs...), InstallerVersion: setupVersion}
	w.mu.Unlock()
	if data.Selected != "" {
		if client, ok := candidateInstall(data.Selected); ok {
			data.ClientEdition = client.Edition
			data.HasClient = true
			if addon, e := bundled.ReadFile("assets/addon.zip"); e == nil {
				data.Addon, _ = inspectAddon(data.Selected, addon)
			}
		}
	}
	if exe, e := companionPayload(); e == nil {
		data.Companion, _ = inspectCompanion(exe)
	}
	out.Header().Set("Content-Type", "text/html; charset=utf-8")
	if e := page.Execute(out, data); e != nil {
		log.Printf("Vorlage: %v", e)
	}
}

func (w *wizard) scan() {
	w.mu.Lock()
	if w.scanning {
		w.mu.Unlock()
		return
	}
	w.scanning = true
	w.mu.Unlock()
	go func() {
		ctx, cancel := context.WithTimeout(context.Background(), 12*time.Second)
		defer cancel()
		results := Discover(ctx, typicalRoots())
		if addon, e := bundled.ReadFile("assets/addon.zip"); e == nil {
			for i := range results {
				if state, e := inspectAddon(results[i].Path, addon); e == nil {
					results[i].AddonCondition = state.Condition
					results[i].AddonVersion = state.InstalledVersion
				}
			}
		}
		w.mu.Lock()
		w.installs = results
		// Auto-select only when there is exactly one compatible target.
		if w.selected == "" {
			matches := []Install{}
			for _, item := range results {
				if item.Supported {
					matches = append(matches, item)
				}
			}
			if len(matches) >= 1 {
				w.selected = matches[0].Path
			}
		}
		w.scanning = false
		w.mu.Unlock()
	}()
}
func (w *wizard) redirect(out http.ResponseWriter, r *http.Request) {
	http.Redirect(out, r, w.prefix+"/", http.StatusSeeOther)
}
func start() error {
	if runtime.GOOS != "windows" {
		return fmt.Errorf("dieser Installer ist nur für Windows gedacht")
	}
	w := &wizard{token: nonce()}
	w.prefix = "/setup/" + nonce()
	mux := http.NewServeMux()
	mux.HandleFunc(w.prefix+"/", w.secure(w.render))
	mux.HandleFunc(w.prefix+"/search", w.secure(func(out http.ResponseWriter, r *http.Request) {
		if r.Method != http.MethodPost {
			http.Error(out, "Methode", 405)
			return
		}
		w.scan()
		w.redirect(out, r)
	}))
	mux.HandleFunc(w.prefix+"/browse", w.secure(func(out http.ResponseWriter, r *http.Request) {
		if r.Method != http.MethodPost {
			http.Error(out, "Methode", 405)
			return
		}
		path, e := selectFolder()
		if e != nil {
			w.notice("Ordnerauswahl: "+e.Error(), true)
		} else {
			w.mu.Lock()
			w.selected = normalizeInput(path)
			w.mu.Unlock()
			w.notice("Ordner übernommen: "+path, false)
		}
		w.redirect(out, r)
	}))
	mux.HandleFunc(w.prefix+"/inspect", w.secure(func(out http.ResponseWriter, r *http.Request) {
		if r.Method != http.MethodPost {
			http.Error(out, "Methode", 405)
			return
		}
		client, err := resolveChoice(r.PostForm.Get("client"))
		if err != nil {
			w.notice("Auswahl: "+err.Error(), true)
		} else {
			w.mu.Lock()
			w.selected = client
			w.mu.Unlock()
			w.notice("Installation geprüft: "+client, false)
		}
		w.redirect(out, r)
	}))
	mux.HandleFunc(w.prefix+"/manage", w.secure(func(out http.ResponseWriter, r *http.Request) {
		if r.Method != http.MethodPost {
			http.Error(out, "Methode", 405)
			return
		}
		w.actionMu.Lock()
		defer w.actionMu.Unlock()
		action := r.PostForm.Get("action")
		if action != "auto" && action != "repair" && action != "uninstall" {
			w.notice("Ungültige Aktion", true)
			w.redirect(out, r)
			return
		}
		chosen := r.PostForm["clients"]
		if len(chosen) == 0 {
			chosen = []string{r.PostForm.Get("client")}
		}
		targets := []string{}
		for _, c := range chosen {
			client, err := resolveChoice(c)
			if err != nil {
				w.notice("Client-Auswahl: "+err.Error(), true)
				w.redirect(out, r)
				return
			}
			targets = append(targets, client)
		}
		w.mu.Lock()
		w.selected = targets[0]
		w.mu.Unlock()
		if active, why := wowIsRunning(); active {
			w.notice("WoW läuft noch ("+why+"). Bitte vollständig beenden.", true)
			w.redirect(out, r)
			return
		}
		if action == "uninstall" {
			if r.PostForm.Get("confirmRemove") != "yes" {
				w.notice("Deinstallation erfordert eine ausdrückliche Bestätigung", true)
				w.redirect(out, r)
				return
			}
		}
		archive, err := bundled.ReadFile("assets/addon.zip")
		if err != nil {
			w.notice("Paketfehler: "+err.Error(), true)
			w.redirect(out, r)
			return
		}
		msgs := []string{}
		for _, client := range targets {
			m, err := performAddonAction(client, action, archive)
			if err != nil {
				w.notice("Addon ("+client+"): "+err.Error(), true)
				w.redirect(out, r)
				return
			}
			msgs = append(msgs, m)
		}
		msg := strings.Join(msgs, " | ")
		if action == "uninstall" {
			if r.PostForm.Get("removeCompanion") == "yes" {
				companionMsg, e := performCompanionAction("uninstall", nil, false, false)
				if e != nil {
					w.notice(msg+" | Companion: "+e.Error(), true)
					w.redirect(out, r)
					return
				}
				msg += " | " + companionMsg
				if len(knownClientPaths()) == 0 {
					if err := configureUpdaterAutostart(false); err != nil {
						msg += " | Auto-Updater bitte separat deaktivieren: " + err.Error()
					} else {
						msg += " | Auto-Updater deaktiviert (kein Client mehr registriert)"
					}
				}
			}
		} else if r.PostForm.Get("companion") == "yes" {
			exe, e := companionPayload()
			if e != nil {
				w.notice(msg+" | Companion-Paket: "+e.Error(), true)
				w.redirect(out, r)
				return
			}
			companionMsg, e := performCompanionAction(action, exe, r.PostForm.Get("autostart") == "yes", r.PostForm.Get("launch") == "yes")
			if e != nil {
				w.notice(msg+" | Companion: "+e.Error(), true)
				w.redirect(out, r)
				return
			}
			msg += " | " + companionMsg
		}
		if action != "uninstall" && r.PostForm.Get("autoupdate") == "yes" {
			if err := configureUpdaterAutostart(true); err != nil {
				msg += " | Automatische Updates nicht eingerichtet: " + err.Error()
			} else {
				startBackgroundUpdater()
				msg += " | Automatische Updates aktiv"
			}
		}
		w.notice(msg, false)
		w.redirect(out, r)
	}))

	mux.HandleFunc(w.prefix+"/launch-checked", w.secure(func(out http.ResponseWriter, r *http.Request) {
		if r.Method != http.MethodPost {
			http.Error(out, "Methode", 405)
			return
		}
		w.actionMu.Lock()
		defer w.actionMu.Unlock()
		client, err := resolveChoice(r.PostForm.Get("client"))
		if err != nil {
			w.notice("WoW-Client: "+err.Error(), true)
			w.redirect(out, r)
			return
		}
		ctx, cancel := context.WithTimeout(r.Context(), 90*time.Second)
		defer cancel()
		message, err := preflightAndLaunch(ctx, client)
		if err != nil {
			w.notice(err.Error(), true)
		} else {
			w.notice(message, false)
		}
		w.redirect(out, r)
	}))
	mux.HandleFunc(w.prefix+"/updates-check", w.secure(func(out http.ResponseWriter, r *http.Request) {
		if r.Method != http.MethodPost {
			http.Error(out, "Methode", 405)
			return
		}
		ctx, cancel := context.WithTimeout(r.Context(), 25*time.Second)
		defer cancel()
		release, err := fetchRelease(ctx)
		if err != nil {
			w.notice("Update-Server: "+err.Error(), true)
		} else {
			w.notice("Signatur geprüft: Addon "+release.Addon.Version+" | Companion "+release.Companion.Version+". Das Update kann jetzt installiert werden.", false)
		}
		w.redirect(out, r)
	}))
	mux.HandleFunc(w.prefix+"/updates-apply", w.secure(func(out http.ResponseWriter, r *http.Request) {
		if r.Method != http.MethodPost {
			http.Error(out, "Methode", 405)
			return
		}
		w.actionMu.Lock()
		defer w.actionMu.Unlock()
		client, err := resolveChoice(r.PostForm.Get("client"))
		if err != nil {
			w.notice("Client: "+err.Error(), true)
			w.redirect(out, r)
			return
		}
		ctx, cancel := context.WithTimeout(r.Context(), 90*time.Second)
		defer cancel()
		release, err := fetchRelease(ctx)
		if err != nil {
			w.notice("Update: "+err.Error(), true)
			w.redirect(out, r)
			return
		}
		msg, err := remoteUpdateClient(ctx, release, client, true)
		w.notice(msg, err != nil)
		if err != nil {
			w.notice("Update: "+err.Error(), true)
		}
		w.redirect(out, r)
	}))
	mux.HandleFunc(w.prefix+"/updates-autostart", w.secure(func(out http.ResponseWriter, r *http.Request) {
		if r.Method != http.MethodPost {
			http.Error(out, "Methode", 405)
			return
		}
		enabled := r.PostForm.Get("enabled")
		if enabled != "yes" && enabled != "no" {
			w.notice("Ungültiger Autostart-Wert", true)
			w.redirect(out, r)
			return
		}
		err := configureUpdaterAutostart(enabled == "yes")
		if err != nil {
			w.notice("Auto-Updater: "+err.Error(), true)
		} else if enabled == "yes" {
			w.notice("Automatische Online-Updateprüfung beim nächsten Windows-Login aktiviert (Push + 2-Minuten-Rückfallprüfung)", false)
		} else {
			w.notice("Automatische Updateprüfung deaktiviert", false)
		}
		w.redirect(out, r)
	}))

	listener, e := net.Listen("tcp", "127.0.0.1:0")
	if e != nil {
		return e
	}
	w.scan()
	url := fmt.Sprintf("http://127.0.0.1:%d%s/", listener.Addr().(*net.TCPAddr).Port, w.prefix)
	log.Printf("Banklord Setup auf %s", url)
	_ = exec.Command("rundll32.exe", "url.dll,FileProtocolHandler", url).Start()
	server := &http.Server{Handler: mux, ReadHeaderTimeout: 5 * time.Second, IdleTimeout: 60 * time.Second, MaxHeaderBytes: 16 << 10}
	return server.Serve(listener)
}
func main() {
	if len(os.Args) > 1 && os.Args[1] == "--companion" {
		companion.Main()
		return
	}
	if len(os.Args) > 1 && (os.Args[1] == "--watch-updates" || os.Args[1] == "--preflight-launch") {
		closeLog := enableLocalUpdaterLog()
		defer closeLog()
	}
	if len(os.Args) > 1 && os.Args[1] == "--preflight-launch" {
		clients := knownClientPaths()
		supported := []string{}
		for _, p := range clients {
			if candidate, ok := candidateInstall(p); ok && candidate.Supported {
				supported = append(supported, p)
			}
		}
		if len(supported) != 1 {
			showPreflightFailure("Bitte zuerst im Installer exakt einen unterstützten WoW-Client einrichten; bei mehreren Installationen den Banklord-Assistenten zum Start verwenden.")
			os.Exit(1)
		}
		ctx, cancel := context.WithTimeout(context.Background(), 90*time.Second)
		defer cancel()
		if _, err := preflightAndLaunch(ctx, supported[0]); err != nil {
			log.Println(err)
			showPreflightFailure(err.Error())
			os.Exit(1)
		}
		return
	}
	if len(os.Args) > 1 && os.Args[1] == "--watch-updates" {
		release, err := acquireWatcherLock(90 * time.Second)
		if err != nil {
			log.Println("Update-Dienst läuft bereits:", err)
			return
		}
		defer release()
		cleanupOldUpdater()
		watchUpdatesReliable()
		return
	}
	if e := start(); e != nil {
		log.Println(e)
		os.Exit(1)
	}
}
