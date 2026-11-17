//go:build !windows

package main

// Only Windows had installations under the old project name.
func migrateLegacyWindows() bool { return false }
