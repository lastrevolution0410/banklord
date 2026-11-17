//go:build windows

package main

import (
	"encoding/csv"
	"os/exec"
	"strings"
)

func wowIsRunning() (bool, string) {
	output, e := exec.Command("tasklist.exe", "/FO", "CSV", "/NH").Output()
	if e != nil {
		return false, ""
	}
	r := csv.NewReader(strings.NewReader(string(output)))
	r.FieldsPerRecord = -1
	for {
		row, e := r.Read()
		if e != nil {
			break
		}
		if len(row) == 0 {
			continue
		}
		exe := strings.ToLower(strings.TrimSpace(row[0]))
		if exe == "wow.exe" || exe == "wowclassic.exe" || exe == "wow-64.exe" || exe == "wowt.exe" || exe == "wowb.exe" {
			return true, exe
		}
	}
	return false, ""
}

func companionIsRunning() (bool, string) {
	output, e := exec.Command("tasklist.exe", "/FO", "CSV", "/NH").Output()
	if e != nil {
		return false, ""
	}
	r := csv.NewReader(strings.NewReader(string(output)))
	r.FieldsPerRecord = -1
	for {
		row, e := r.Read()
		if e != nil {
			break
		}
		if len(row) < 1 {
			continue
		}
		exe := strings.ToLower(strings.TrimSpace(row[0]))
		if exe == "banklord-companion.exe" || strings.HasPrefix(exe, "banklord-companion-") {
			return true, exe
		}
	}
	return false, ""
}
