package companion

import (
	"bytes"
	"os"
	"path/filepath"
)

// MigrateLegacyConfig copies settings written under the old project name
// "WoWGuildBank" (up to 0.9.8) to the Banklord folder. The token stays
// encrypted as before; the SavedVariables path now points to Banklord.lua,
// which the setup creates from the old file. Old files are left in place.
func MigrateLegacyConfig() {
	cfg, err := os.UserConfigDir()
	if err != nil {
		return
	}
	oldDir, newDir := filepath.Join(cfg, "WoWGuildBank"), filepath.Join(cfg, "Banklord")
	for _, name := range []string{"companion.json", "known_clients.json"} {
		dest := filepath.Join(newDir, name)
		if _, err := os.Stat(dest); err == nil {
			continue
		}
		b, err := os.ReadFile(filepath.Join(oldDir, name))
		if err != nil || len(b) > 1<<20 {
			continue
		}
		if name == "companion.json" {
			b = bytes.ReplaceAll(b, []byte("WoWGuildBank.lua"), []byte("Banklord.lua"))
		}
		if os.MkdirAll(newDir, 0o700) == nil {
			_ = os.WriteFile(dest, b, 0o600)
		}
	}
}
