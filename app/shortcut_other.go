//go:build !windows

package main

func showPreflightFailure(message string) {}

func installCompanionShortcuts(target string) error { return nil }
func removeCompanionShortcuts(target string) error  { return nil }
func refreshCompanionShortcutIcons()                {}
