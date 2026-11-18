package main

import (
	"errors"
	"os"
	"path/filepath"
	"time"

	"banklord/companion"
)

// openCompanion makes sure the installed companion runs and returns its local
// page address, so the setup can show it in the same browser tab ("" if not).
func openCompanion() string {
	if u, err := companion.RunningURL(); err == nil {
		return u
	} else if errors.Is(err, companion.ErrOldRunning) {
		stopCompanion()
		time.Sleep(time.Second)
	}
	dir, err := companionDirectory()
	if err != nil {
		return ""
	}
	os.Setenv("BANKLORD_NO_BROWSER", "1")
	err = launchCompanion(filepath.Join(dir, "Banklord-Companion"+exeSuffix))
	os.Unsetenv("BANKLORD_NO_BROWSER")
	if err != nil {
		return ""
	}
	for i := 0; i < 40; i++ {
		time.Sleep(250 * time.Millisecond)
		if u, err := companion.RunningURL(); err == nil {
			return u
		}
	}
	return ""
}
