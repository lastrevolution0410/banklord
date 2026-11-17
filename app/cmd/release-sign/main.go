// Offline-only release signing utility. NEVER put the PEM private key on the webserver.
// Produces OUT_DIR/manifest.json and OUT_DIR/files/<sha256>.<ext> (content-addressed).
package main

import (
	"crypto/ed25519"
	"crypto/sha256"
	"crypto/x509"
	"encoding/base64"
	"encoding/hex"
	"encoding/json"
	"encoding/pem"
	"fmt"
	"os"
	"path/filepath"
	"time"
)

// Override with BANKLORD_UPDATE_HOST for forks and self-hosted update servers.
var base = "https://" + hostOr(os.Getenv("BANKLORD_UPDATE_HOST"), "banklord.mgc-nom.net") + "/updates/preview/files/"

func hostOr(v, d string) string {
	if v == "" {
		return d
	}
	return v
}

type File struct {
	Version string `json:"version"`
	URL     string `json:"url"`
	SHA256  string `json:"sha256"`
}
type Payload struct {
	Protocol    int    `json:"protocol"`
	Channel     string `json:"channel"`
	Client      string `json:"client"`
	PublishedAt string `json:"published_at"`
	Addon       File   `json:"addon"`
	Companion   File   `json:"companion"`
	Updater     File   `json:"updater"`
	Web         File   `json:"web"`
	// macOS/Linux app zips, same version as the updater.
	Apps map[string]File `json:"apps,omitempty"`
}
type Envelope struct {
	Payload   string `json:"payload"`
	Signature string `json:"signature"`
}

func main() {
	if len(os.Args) < 11 || (len(os.Args)-11)%2 != 0 {
		fmt.Fprintln(os.Stderr, "Verwendung: release-sign PRIVATE.pem OUT_DIR ADDON.zip VERSION COMPANION.exe VERSION UPDATER.exe VERSION WEB.zip VERSION [PLATTFORM APP.zip]…")
		os.Exit(2)
	}
	data, err := os.ReadFile(os.Args[1])
	die(err)
	blk, _ := pem.Decode(data)
	if blk == nil {
		die(fmt.Errorf("PEM ungültig"))
	}
	pk, err := x509.ParsePKCS8PrivateKey(blk.Bytes)
	die(err)
	sk, ok := pk.(ed25519.PrivateKey)
	if !ok {
		die(fmt.Errorf("kein Ed25519-Schlüssel"))
	}
	out := os.Args[2]
	die(os.MkdirAll(filepath.Join(out, "files"), 0o755))
	file := func(path, version string) File {
		b, err := os.ReadFile(path)
		die(err)
		sum := sha256.Sum256(b)
		name := hex.EncodeToString(sum[:]) + filepath.Ext(path)
		die(os.WriteFile(filepath.Join(out, "files", name), b, 0o644))
		return File{version, base + name, hex.EncodeToString(sum[:])}
	}
	payload := Payload{Protocol: 1, Channel: "preview", Client: "mop-classic-5.5.4", PublishedAt: time.Now().UTC().Format(time.RFC3339),
		Addon: file(os.Args[3], os.Args[4]), Companion: file(os.Args[5], os.Args[6]), Updater: file(os.Args[7], os.Args[8]), Web: file(os.Args[9], os.Args[10])}
	for i := 11; i+1 < len(os.Args); i += 2 {
		if payload.Apps == nil {
			payload.Apps = map[string]File{}
		}
		payload.Apps[os.Args[i]] = file(os.Args[i+1], os.Args[8])
	}
	p, err := json.Marshal(payload)
	die(err)
	e, _ := json.MarshalIndent(Envelope{base64.StdEncoding.EncodeToString(p), base64.StdEncoding.EncodeToString(ed25519.Sign(sk, p))}, "", "  ")
	die(os.WriteFile(filepath.Join(out, "manifest.json"), append(e, '\n'), 0o644))
	fmt.Println("Signiertes Release erzeugt in", out)
}

func die(e error) {
	if e != nil {
		fmt.Fprintln(os.Stderr, e)
		os.Exit(1)
	}
}
