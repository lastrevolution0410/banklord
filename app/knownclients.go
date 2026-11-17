package main

import (
	"encoding/json"
	"os"
	"path/filepath"
	"strings"
)

// This manifest contains only local game paths, never tokens or passwords.
func recordClientPath(client string) error {
	cfg, e := os.UserConfigDir()
	if e != nil {
		return e
	}
	folder := filepath.Join(cfg, "Banklord")
	if e = os.MkdirAll(folder, 0700); e != nil {
		return e
	}
	dest := filepath.Join(folder, "known_clients.json")
	var paths []string
	if raw, e := os.ReadFile(dest); e == nil {
		_ = json.Unmarshal(raw, &paths)
	}
	key := strings.ToLower(filepath.Clean(client))
	found := false
	for _, old := range paths {
		if strings.ToLower(filepath.Clean(old)) == key {
			found = true
			break
		}
	}
	if !found {
		paths = append(paths, client)
	}
	if len(paths) > 20 {
		paths = paths[len(paths)-20:]
	}
	data, e := json.MarshalIndent(paths, "", "  ")
	if e != nil {
		return e
	}
	tmp := dest + ".tmp"
	if e = os.WriteFile(tmp, data, 0600); e != nil {
		return e
	}
	if e = os.Rename(tmp, dest); e != nil {
		_ = os.Remove(tmp)
		return e
	}
	return nil
}
