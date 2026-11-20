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
	"runtime"
	"strings"
	"sync"
	"time"
)

var page = template.Must(template.New("wizard").Parse(`<!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="referrer" content="no-referrer">{{if .Scanning}}<meta http-equiv="refresh" content="3">{{end}}<title>Banklord – Verwaltung</title><link rel="icon" href="data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAADAAAAAwCAYAAABXAvmHAAAPnklEQVR42sWaa4xcZ3nHf8/7nsvMzuxtdme99jrr29rr2s7NNglQGgs3JTRQAokMSVsoqoRQI1oqWlRVrVpoBRJVgAoaFLUSEimlkEQQU9q0BFQFEZqbEzvGNo7XXq+9vux9d3Z2d2bOOe/TD2f25l07TlWFM3o/zJlzzjz3//953gOrHQcO2FXOypu8rkemFRfO3+w23HxPi/jJbwlyJ6rbFMmjTngTDsEohhmBPpAfV8R8/+KLT44tkVdXU0Dmf9i0+70PishfiPXWpZc7VHlTDxFATGrNJBlS3Bf6D/3gy1fKKosn/lr27Llkx/TCN6wXPOCSGHUuBhUE4ZdxKAqiYsQz1idJou/FfvZ3Bp97vFpXQE09vgx81o0lg49aL3ggrlUjdYmCeoBFMde9wEh9mfqa/w5v4Dnpsyyop85pXKtG1vofsJWZbwOayowI+/Z5PPNMvPHWuz9uveCROK7WBAneWMwqIqlJEickKjhdEql1DawoVurXKugbdKyiNc8Lgziq/unA4ae+yL59ngCyec+dTS7x+jCmgHMgYq5P8HRFToic4BnIB46mMCbnJ4TWAVBzhpmapVSzTFctsQPPKL7R5Rl5PSEloqqUfVxP3+H/HPEAjSP7PhsE7S6qJojY68lYI1BLhMQJHfmErYVZNjTNUszWaMxAJjB4NlUxThyVmqNchZFZn3PTOV4bzzJU9rCiBFZTj12PzZwmxg+aoiS6D3jEI032u9B5p+s175fUDMxEQldTzO3rSvQ0T9Pe5FFob6OlrUAunyfMZLBeWrqTOKFarTIzXWZqfJzx0TFGS2Ocnmrk+YtNDE55ZDxdePa1ZVBBnaq6uxYUwLFVxQmoQeUaVleiJC1xd26a5i1rJ+loDenZuZcNvdswtVlC6wg8Ayiu7kmT1kRqsaOWGFzYwMDJPtYeO0Zv4SIvXW7hmbONxE6uxxuizomo9gDGA0RxubRA6DVDphILTRnl/b1j9LZX6N6yhQ2butmyfQvSVaR07DWOHLvIyQsVBobmmCxHADTnfLrXZOntynDLjrU079pEsZCn0NrEwNnzNGX7uKGpwpMnC0zMGkLv9ZRwKDT09LzbTz2guriuInw1EgoNCQ/sGmVDu2XnrbexrqtIg6+c7z/PD544xhM/6ufEQJnp2Riny2HTCOSzHr3dZ7j3zou8b08THYUMjY3baC+2Ebx8hMZgmG//vMhQ2ZC5lhJLZBVAum+886hYb6e62MHyCiQCcQL5UPnwTaNs7gzZ/da9FFpyBNbxb/8zwkOP9fOLgWkyoUc2tFgjrBbKiVMqtYS5SkzP+jyfOrCJe9/RQayGialZDj13iIHLszz6apHJOcG3q9rUiTFGnTsTzNodFpDmjs0PikhHPZHlitKFInxw1wTbOg17334bbS05cDF/+80z/M2jfcxUE1ryIb5nljnzygUQeJZ8g89kOebgz4YZLUXcsauFXDakUOygPHqJ9nCWo0MNqK6KFCqIoDphY/OwmY+o1T4iymwMd2ycobe9xs5bbqbQkkNdzJ888hpfO3iOlrxPxjckzqV/KKy+5s2nSpw4Ql9oa/L5+lOD/NE/nCCOY1qas+zafQs9bTHv3FxmLhZEVpfNkWKMWWrppUuAWgxdjQm3rStxw5bNrFtXJLCOz3+rn+/89yXWtAYkLo3VKFaqNUc1WrlqkZIoWCMpSQOcQpwoa1oDDv5smM98ow9PHJ2dBTZs7WFvZ4kNLTHVuK78FfLV5cdbKf0iyiZOeOv6Mh2tGTZt6qYhcBx8doR/+vfzFFsCokQXhGlv9mkI7ULy6hLKGCdKaSZmrFSjIWPxrCyEVBQrHS0Bjz59kT3bmrl/fycbN65n6MIgb1s/zWOTzXV8uCKu62e8NKkdqgadz2yBWgLFvLK1dYaenXvp2r6Z4f5BHvpOP2GweK01wnQ54rMf7eFD+zqZKMdphKbRhAJR4pirJDx7bJIvPX6W0myM7y0q4ZySy1i+9EQ/+3cX6erdzNbJaS6PPM/axjxD0wbf6pKE1oX/N6vFj6DUEmFbW4X2Jp9NvdvIrCvyHy+X+MX5aRoydkWJ86wQ+obANxQaAym2+Nre7GuxJaC7o4ENnQ187D038PAnt0cGjXQJYDqFTGjovzjDwRenyKxtZ2PvVtqaQra3V4hcShhXxtFCCK0ss75RNjVXKLQXoDbLxPFTPPZ0P5nAw7lV7nEQO8Ua3D8+efylYwOzxvewRow25/zM77578+a25kzmjpvazN23tQw+8dOJ7uacJ0ndEi5RGjI+T/yon4/c1YUkMW3FNjZcHCWw2avSs1WBLFHIB0oxF9HSViC0jiPHLnFioEw2tLirIIyIEFjip14YtT89Ge/Oh6IiIuNT1eRSSeSrn9ipUSJ2xw2ZUhTHVRE/M2/JeS+cGpzh8NEL3L6rSHNbgWLDZZpCR6kCVpbV9sUQSh3i6j2aEjtoChMaM0JDPk/gGU5emGN6Nk5B6nWOxKkmDkkcEjvEeNZrbwotIJ4R+ganxj1rPL3CrEaE2UrC8fNz+J6Qy+fJZwzNmYTYpbIt/Sx6wC2WDAGcS3l9JjCEmRBQBobmrovyRona++/csPbW7ZEGvqCKdrVneN/bi4ixcvz0pSOP/2Q4bMq3eYmLViC/AueG5lBVwjAkDAyNfoI6iyxlO3qNMqpAaB2eZ/A9D6fKZDm6dv9UB6tagv3o3Vu6/GWl0lGuJIxPlUc//tAr4zXydzSQaMLKxkkEJssxLlE83+J7ltBzdYvr6mUUTcuSav0y5ZrM9NqNjjA4ND0laEUVMYKEgfXzuaDRZYK2b352/zv/8CtH9dCpacllrp5Py4vKEvl0vjVbVoXc0kkFglCNhThW4jjBSEqJ9drtXuo5n/iPv/zy4Z8cn12T9Y0RAd8Td9++rton79ta7O4I9O8+trV095+/ZJzaxiWYt1BPmnMexghxnBAnjmriXTEOWoRic2UEqYLBMVMTKrW0kwKhe02G68hfDLi5yLbGNGx3NrstIrutHIXbv3rwUsfR/pLUYmRDZ4O/da1/qRrpimcK0L0mixihWq1SqSWUqwZDfTZ1BWnwFsYv825RsAamKobpijJTLlOLld6uLPmsJbkOl0/P1aqT5VjjSFRB4kRpzfvSkvdxzuEcgiYlYXkKOFWyoWH7+ixxrMyWZyjPOaYqghWth/ZqOcAiDsw3H6UKjMxYpsbHqSaGG3eupbf7DK+eriOxW4WdKBolat77tnXF7nURoZ9SN98T3v2Wdt3QmXHWenZ8YnL42EDZNOQLOBcpiBiBuZpj2/ocu29cR9U5psbHGZ0xTM6BNcupxPwX72rjkiiGs5M+4yPjEDZQ2LmRA79+keePHSLfYFegsbVC4BkpY7wH79u+0cqigtYIUaI4FWslqXzh0cOHIzL7RRzzZdRaw+xclXv3b6R4cy/jJ84xNjLG2cmAagS5YPVW31vGH+YdpOBb5RfDHr9amuXsyT4aC3nes7eJR9bnuTxeI/RlARdEhGotjp2Lpl0cSakUq1OVtLFHncM51WiqVBl96FuvnnnyhcotrS2NjUkSK4iIwFw1oXtNA/fc3kLl0ihnT51mbLLCieEmPHPlbHZe1r55NqrLGJ4CvoFLU8KpsZB1x4/T1tpIsZDhUwc28YmvHCcT+pAoiVPNZT354nfOVL/23VODiXPLeoy0gYFarIyVotDzw3e1tjRmkjhWJA0xa4SJuYjP/F4PXQWPy6/10/fzY5yeCBicZJX+WNHX6wdU01x49mzAr3SUOXt2kHzjNu59Rwcvnpzi608NsqY1IIpVBKVcIVeqeDfKiuF9OhkWgUJrDjQhSRaF9z1heKLG/fvX8tv7O5mNDAMDFxgem+bZs7m0F1jB5PRKMrfifB2NlXPj8Py5DE0NZ2hrb6NrXYHPfHgLY1M1Dv5smI6WIE1oA1Z0Ffxb8KomSbLQZIqklh+eqPGuve187ve3kmAZHp7g7Kk+XroQcmYEGoKrTCc05T0WkIbC2gdFpKOe57Kc50P/uNDdkiBzYxQ6OmhoCLlrbxuT5ZjnTkxhjBD6FhTRa+66iBgRrDXUIsdkOeL+/Wv50h9sJxN6TE1XeeWFl3ntUo3HD/sL7FOvtIiIqOpEKJWHLSC5lrX1qcRKBebHKqfHPba0VpibGqO1WCTXEHLXngLda7K8errEhZFZRATfE6wRjKxcAJWaY2q6Smch5K8+0sOnP7gR46XCH3ruZc5fnuabhzKU5hRv9bFKfSrhJkKpPiyAdGzec9QYu1PVuWWN/rKpHHQ2wUf2VunuyLLr1pvo7Gwj6zuGJ2O+90KJ7/64n1ODM8xWk9TDsszbZENDT1eO9+/fyL23t7Cu4DEXGYaGJzj6yqsMDs3wz4dCzk9A1udq7NeJGKMuOdNopnakCmzafU0FlLSZqMRQyAkfujViWwds6NlC98b1bO7dDGvbGT/6GoeOXuDE+TnODc0xWY5RoCXn0b0my/b1WfbeuI62m3vh0ij9r/VzbuACA6f66Bt2PHY4YKikZNMCdzX2u6iALe0QgOKm3S8aa/aqW12B5SN18IzwG9sdt3dHdBRy9OzcQXdvD6YyQ2Bd2rA7XUBrYwQxKTmsOouGOc6dOk3fz48zPDbNi4M+PzxhqMZK6PF6fYcTMcY5d2yk/+WbvDoBOyUqe1IPmKsq4DTFB1Xl+68Kr14I+LXNc1waeZ72V45QKLbRXCiQy+cIwxCvPl6Pk4RqpcrszAxT4xOMj4wxOjlH37jHT88EnB6BjK8EllX77RXlR0RFOQM4Lx1rJE9bYx5AEURf73YghfbBCfiXlyxdrR471jg2FS5RzF+kMWvJBHZRgTihWkuYnksYKQv9Ex4nhjIMTqTYkwtS41zXTqiqiiCI/nABaprW7yiEftAnSHMdNK5vi2mxCyNKIPCEliy0ZJXGEEIvlaiaCNNVmJwTpuagGoFnldAukMDr32QSUVWdE/V7RgZevCywz4Nn4vaNt3zSWvv3Lo5rCG9sk29+GK0QuzQBnS62lFLfHLEGPMPChuAb3ntWasbzgiSO/3J04MjnoL7JBwcMPJ60b7jpSWv9e1wSRYA3D/dvbMdyyUb1KqH3f9wvV5TIWC9Ikujp0YHe30xPP+5k6Ybj+vVvDSt29l+N9e7RJEHVxSmVkV/ORnfaoKiIeGI9XJL8l2YqB8ZOniwvgtoqrxq0bbzx06LyZ8bYNkV5098zWBKbkm5MTuL0i6Pnjn7+ygZerhIB2rFp15rEmQ8IeidKj0L+Ki+I/P+/YJD+yQxwRjA/ThzfGx88cuFKQ19DmAMWHk+Wnunp6QnfTOP39fVVX08mgP8F7rYjAB4HwbQAAAAASUVORK5CYII="><style>
:root{color-scheme:dark;font-family:system-ui,Segoe UI,Arial,sans-serif}body{background:#101826;color:#ecf2f8;margin:0;padding:25px}main{max-width:900px;margin:0 auto}h1{font-size:1.7rem;margin:.4rem 0}p{color:#bfd0e1;line-height:1.5}.panel{background:#1b283b;border:1px solid #34455e;border-radius:13px;padding:22px;margin:15px 0}.ok{color:#a0ebc1}.warn{color:#f2d19b}.danger{color:#ffa7a7}label{display:block;margin:12px 0;line-height:1.45}input[type=text]{box-sizing:border-box;width:100%;font-size:1rem;padding:11px;background:#0d1522;color:#fff;border:1px solid #5a708f;border-radius:8px}button{padding:11px 19px;background:#315fb8;color:#fff;border:0;border-radius:8px;cursor:pointer;font-size:1rem;margin:4px 7px 4px 0}button.secondary{background:#435065}button.danger{background:#993e47}button:disabled{background:#444;cursor:not-allowed}.client{padding:13px;margin:9px 0;background:#121d2b;border:1px solid #34455e;border-radius:9px}.muted{font-size:.88rem;color:#aec2d8}code{overflow-wrap:anywhere}footer{font-size:.8rem;color:#93a7bd}small{display:block;color:#b8c9dc;margin:6px 0}input[type=checkbox]{width:1rem;height:1rem}b{color:white}</style></head><body><main>
<h1>Banklord einrichten</h1><p>Setup {{.InstallerVersion}}. Ein Klick installiert oder aktualisiert Banklord in allen gefundenen WoW-Versionen. Eigene Einstellungen, andere Addons und gespeicherte Daten bleiben unberührt.</p>
{{if .Message}}<div class="panel {{if .IsError}}danger{{else}}ok{{end}}">{{.Message}}</div>{{end}}
<div class="panel">
{{if .Scanning}}<p class="warn">WoW wird gesucht … diese Seite aktualisiert sich selbst.</p>{{else}}
<form method="post" action="{{.Prefix}}/manage"><input type="hidden" name="token" value="{{.Token}}">
<h2>Gefundene WoW-Versionen</h2>
{{range .Installs}}<label class="client"><input type="checkbox" name="clients" value="{{.Path}}" checked> <b>{{.Edition}}</b> · Banklord: <b>{{.AddonCondition}}</b>{{if .AddonVersion}} ({{.AddonVersion}}){{end}}<br><small>{{.Reason}} · <code>{{.Path}}</code></small></label>{{else}}<p class="warn">Keine WoW-Installation gefunden. Unten unter „Erweitert“ den Ordner von Hand wählen.</p>{{end}}
<input type="hidden" name="companion" value="yes"><input type="hidden" name="autostart" value="yes"><input type="hidden" name="autoupdate" value="yes">
<p class="muted">Dazu werden der Companion (verbindet Spiel und Website) und automatische Updates eingerichtet. WoW muss dafür geschlossen sein.</p>
{{if .Installs}}<button name="action" value="auto" type="submit">Installieren / Aktualisieren</button>{{end}}
</form>
<form method="post" action="{{.Prefix}}/search"><input type="hidden" name="token" value="{{.Token}}"><button class="secondary">Erneut suchen</button></form>{{end}}
</div>
<div class="panel"><h2>Danach</h2><p>1. Nach der Installation wechselt diese Seite automatisch zum <b>Banklord Companion</b>. Später öffnest du ihn über das Desktop-Symbol <b>Banklord Companion</b>, das Startmenü oder hier:</p><form method="post" action="{{.Prefix}}/open-companion"><input type="hidden" name="token" value="{{.Token}}"><button class="secondary">Companion öffnen</button></form><p>2. <b>WoW starten</b> und mit einem Charakter deiner Gilde einloggen. Mehr musst du nicht tun.</p><p>3. Sobald ein Bankverwalter online ist, erscheint im Spiel ein Fenster: <b>„Jetzt übertragen“</b> klicken. Danach im Companion <b>„Verbinden“</b> klicken und ein Passwort für die Website festlegen. Fertig!</p><p class="muted">Im Spiel öffnet <b>/banklord</b> oder der Knopf an der Minikarte das Addon.</p></div>
<details class="panel"><summary><b>Erweitert</b> – Ordner von Hand, Reparieren, Updates, Deinstallieren</summary>
<form method="post" action="{{.Prefix}}/browse"><input type="hidden" name="token" value="{{.Token}}"><button type="submit" class="secondary">Ordner durchsuchen …</button></form>
<form method="post" action="{{.Prefix}}/inspect"><input type="hidden" name="token" value="{{.Token}}"><label>WoW-Clientordner<input name="client" type="text" list="paths" value="{{.Selected}}" required placeholder="D:\World of Warcraft\_classic_"></label><datalist id="paths">{{range .Installs}}<option value="{{.Path}}">{{.Edition}}</option>{{end}}</datalist><button type="submit" class="secondary">Prüfen</button></form>
{{if .HasClient}}<div class="client"><b>{{.ClientEdition}}</b><br><code>{{.Selected}}</code><p>Addon: <b>{{.Addon.Condition}}</b> ({{if .Addon.InstalledVersion}}{{.Addon.InstalledVersion}}{{else}}–{{end}} / Paket {{.Addon.PackagedVersion}}) · Companion: <b>{{.Companion.Condition}}</b> ({{if .Companion.InstalledVersion}}{{.Companion.InstalledVersion}}{{else}}–{{end}} / Paket {{.Companion.PackagedVersion}})</p></div>{{end}}
<form method="post" action="{{.Prefix}}/manage"><input type="hidden" name="token" value="{{.Token}}"><input type="hidden" name="client" value="{{.Selected}}"><input type="hidden" name="companion" value="yes"><input type="hidden" name="autostart" value="yes"><input type="hidden" name="autoupdate" value="yes">
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
	lastSeen                         time.Time
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
		// form-action allows the redirect to the local companion page on its own port.
		out.Header().Set("Content-Security-Policy", "default-src 'none'; img-src data:; style-src 'unsafe-inline'; form-action 'self' http://127.0.0.1:*; base-uri 'none'; frame-ancestors 'none'")
		w.mu.Lock()
		w.lastSeen = time.Now()
		w.mu.Unlock()
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
			companionMsg, e := performCompanionAction(action, exe, r.PostForm.Get("autostart") == "yes", false)
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
		// Show the companion right away in this tab: the next step is pasting the connect code there.
		if action != "uninstall" && r.PostForm.Get("companion") == "yes" {
			if u := openCompanion(); u != "" {
				http.Redirect(out, r, u, http.StatusSeeOther)
				return
			}
		}
		w.redirect(out, r)
	}))
	mux.HandleFunc(w.prefix+"/open-companion", w.secure(func(out http.ResponseWriter, r *http.Request) {
		if u := openCompanion(); u != "" {
			http.Redirect(out, r, u, http.StatusSeeOther)
			return
		}
		w.notice("Companion konnte nicht gestartet werden. Bitte zuerst installieren.", true)
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
	_ = command("rundll32.exe", "url.dll,FileProtocolHandler", url).Start()
	// No console window: the setup ends by itself once its page was idle for an hour.
	w.lastSeen = time.Now()
	go func() {
		for range time.Tick(time.Minute) {
			w.mu.Lock()
			idle := time.Since(w.lastSeen) > time.Hour
			w.mu.Unlock()
			if idle && w.actionMu.TryLock() {
				os.Exit(0)
			}
		}
	}()
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
		retireLegacyInstall()
		quietCompanionAutostart()
		watchUpdatesReliable()
		return
	}
	if e := start(); e != nil {
		log.Println(e)
		os.Exit(1)
	}
}
