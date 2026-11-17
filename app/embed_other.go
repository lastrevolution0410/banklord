//go:build !windows

package main

import (
	"embed"
	"os"
)

const exeSuffix = ""

// On macOS and Linux this single binary is setup, updater and companion
// (started with --companion), so the companion payload is the binary itself.
//
//go:embed assets/addon.zip
var bundled embed.FS

func companionPayload() ([]byte, error) {
	me, err := os.Executable()
	if err != nil {
		return nil, err
	}
	return os.ReadFile(me)
}
