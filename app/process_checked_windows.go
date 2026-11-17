//go:build windows

package main

import (
	"encoding/csv"
	"os/exec"
	"strings"
)

func wowRunningChecked() (bool, error) {
	out, err := exec.Command("tasklist.exe", "/FO", "CSV", "/NH").Output()
	if err != nil {
		return true, err
	}
	r := csv.NewReader(strings.NewReader(string(out)))
	r.FieldsPerRecord = -1
	for {
		row, e := r.Read()
		if e != nil {
			break
		}
		if len(row) == 0 {
			continue
		}
		s := strings.ToLower(strings.TrimSpace(row[0]))
		if s == "wow.exe" || s == "wowclassic.exe" || s == "wow-64.exe" || s == "wowt.exe" || s == "wowb.exe" {
			return true, nil
		}
	}
	return false, nil
}
