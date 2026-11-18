package main

import (
	"os"
	"path/filepath"
	"strings"
	"testing"
)

// Console tools started by the windowless setup/updater must not open a
// console window on Windows; they all go through command()/commandContext().
func TestNoDirectExecOnWindows(t *testing.T) {
	files, _ := filepath.Glob("*.go")
	for _, f := range files {
		if strings.HasSuffix(f, "_test.go") || f == "hidden_windows.go" || f == "hidden_other.go" || strings.HasSuffix(f, "_unix.go") {
			continue
		}
		b, err := os.ReadFile(f)
		if err != nil {
			t.Fatal(err)
		}
		if strings.Contains(string(b), "exec.Command(") || strings.Contains(string(b), "exec.CommandContext(") {
			t.Errorf("%s starts a program directly; use command()/commandContext()", f)
		}
	}
}
