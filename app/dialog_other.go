//go:build !windows

package main

import "errors"

func selectFolder() (string, error) {
	return "", errors.New("Ordnerdialog nur in der Windows-EXE verfügbar")
}
