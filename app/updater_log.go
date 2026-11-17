package main

import (
	"log"
	"os"
	"path/filepath"
)

// Local capped diagnostic log, no access tokens and no Windows console window.
func enableLocalUpdaterLog() func() {
	base := os.Getenv("LOCALAPPDATA")
	if base == "" {
		base, _ = os.UserConfigDir()
	}
	dir := filepath.Join(base, "Banklord", "Updater")
	if os.MkdirAll(dir, 0700) != nil {
		return func() {}
	}
	path := filepath.Join(dir, "update.log")
	if fi, err := os.Stat(path); err == nil && fi.Size() > 2<<20 {
		_ = os.Rename(path, path+".previous")
	}
	f, err := os.OpenFile(path, os.O_CREATE|os.O_APPEND|os.O_WRONLY, 0600)
	if err != nil {
		return func() {}
	}
	log.SetOutput(f)
	return func() { f.Close() }
}
