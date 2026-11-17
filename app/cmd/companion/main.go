// Command companion is the Windows companion executable. On macOS and Linux
// the same code runs inside the single Banklord app (see ../../app_unix.go).
package main

import "banklord/companion"

func main() { companion.Main() }
