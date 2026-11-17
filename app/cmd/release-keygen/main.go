// Creates an Ed25519 release signing key (PKCS#8 PEM, mode 0600) and prints the
// base64 public key to embed in the clients and the server. Run once, offline.
package main

import (
	"crypto/ed25519"
	"crypto/rand"
	"crypto/x509"
	"encoding/base64"
	"encoding/pem"
	"fmt"
	"os"
)

func main() {
	if len(os.Args) != 2 {
		fmt.Fprintln(os.Stderr, "Verwendung: release-keygen PRIVATE.pem")
		os.Exit(2)
	}
	if _, err := os.Stat(os.Args[1]); err == nil {
		fmt.Fprintln(os.Stderr, "Schlüsseldatei existiert bereits – nicht überschrieben.")
		os.Exit(1)
	}
	pub, priv, err := ed25519.GenerateKey(rand.Reader)
	if err != nil {
		panic(err)
	}
	der, err := x509.MarshalPKCS8PrivateKey(priv)
	if err != nil {
		panic(err)
	}
	f, err := os.OpenFile(os.Args[1], os.O_WRONLY|os.O_CREATE|os.O_EXCL, 0o600)
	if err != nil {
		panic(err)
	}
	if err := pem.Encode(f, &pem.Block{Type: "PRIVATE KEY", Bytes: der}); err != nil {
		panic(err)
	}
	f.Close()
	fmt.Println(base64.StdEncoding.EncodeToString(pub))
}
