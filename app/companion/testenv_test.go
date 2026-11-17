package companion

import (
	"os"
	"testing"
)

// isolateConfig points os.UserConfigDir at a fresh directory on every OS
// (XDG on Linux, HOME on macOS, APPDATA/LOCALAPPDATA on Windows).
func isolateConfig(t *testing.T) string {
	d := t.TempDir()
	for _, k := range []string{"XDG_CONFIG_HOME", "HOME", "APPDATA", "LOCALAPPDATA"} {
		t.Setenv(k, d)
	}
	cfg, err := os.UserConfigDir()
	if err != nil {
		t.Fatal(err)
	}
	return cfg
}
