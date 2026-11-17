//go:build windows

package main

import "embed"

const exeSuffix = ".exe"

// Windows ships the companion as a separate executable inside the setup.
//
//go:embed assets/addon.zip assets/companion.exe
var bundled embed.FS

func companionPayload() ([]byte, error) { return bundled.ReadFile("assets/companion.exe") }

// platformRoots: Windows uses drive letters and the registry instead (see discover.go).
func platformRoots() []string { return nil }
