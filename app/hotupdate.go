package main

import (
	"crypto/sha256"
	"encoding/hex"
	"errors"
	"os"
	"path/filepath"
	"strings"
)

// Hot reload is ONLY allowed when the list of TOC-loaded files, SavedVariables
// declarations and Interface targets are unchanged. This is a conservative
// compatibility gate, NOT proof that a running client will accept a reload.
func reloadCompatible(oldTOC, newTOC []byte) bool {
	important := func(data []byte) string {
		lines := strings.Split(strings.ReplaceAll(string(data), "\r\n", "\n"), "\n")
		fields := []string{}
		for _, raw := range lines {
			line := strings.TrimSpace(raw)
			if line == "" || strings.HasPrefix(line, "#") && !strings.HasPrefix(line, "##") {
				continue
			}
			if strings.HasPrefix(line, "##") {
				low := strings.ToLower(line)
				if !(strings.HasPrefix(low, "## interface:") || strings.HasPrefix(low, "## savedvariables:") || strings.HasPrefix(low, "## savedvariablespercharacter:")) {
					continue
				}
			}
			fields = append(fields, strings.ToLower(line))
		}
		return strings.Join(fields, "\n")
	}
	return important(oldTOC) != "" && important(oldTOC) == important(newTOC)
}

func verifiedCacheDir() (string, error) {
	base := os.Getenv("LOCALAPPDATA")
	if base == "" {
		var err error
		base, err = os.UserConfigDir()
		if err != nil {
			return "", err
		}
	}
	path := filepath.Join(base, "Banklord", "Updater", "cache")
	if err := os.MkdirAll(path, 0700); err != nil {
		return "", err
	}
	return path, nil
}

// Verified cache keys are taken from signed manifest SHA256 digests, and the
// cached content is re-hashed every time before use.
func cacheVerifiedUpdate(f releaseFile, downloaded func() ([]byte, error)) ([]byte, error) {
	dir, err := verifiedCacheDir()
	if err != nil {
		return nil, err
	}
	if len(f.SHA256) != 64 {
		return nil, errors.New("invalid cache hash")
	}
	for _, c := range f.SHA256 {
		if !strings.ContainsRune("0123456789abcdefABCDEF", c) {
			return nil, errors.New("invalid cache hash")
		}
	}
	dest := filepath.Join(dir, strings.ToLower(f.SHA256)+".pkg")
	if b, e := os.ReadFile(dest); e == nil {
		sum := sha256.Sum256(b)
		if strings.EqualFold(hex.EncodeToString(sum[:]), f.SHA256) {
			return b, nil
		}
		_ = os.Remove(dest)
	}
	b, err := downloaded()
	if err != nil {
		return nil, err
	}
	sum := sha256.Sum256(b)
	if !strings.EqualFold(hex.EncodeToString(sum[:]), f.SHA256) {
		return nil, errors.New("cached update verification failed")
	}
	tmp, err := os.CreateTemp(dir, ".download-*.tmp")
	if err != nil {
		return nil, err
	}
	tmpName := tmp.Name()
	defer os.Remove(tmpName)
	if _, err = tmp.Write(b); err != nil {
		tmp.Close()
		return nil, err
	}
	if err = tmp.Sync(); err != nil {
		tmp.Close()
		return nil, err
	}
	if err = tmp.Close(); err != nil {
		return nil, err
	}
	if err = os.Rename(tmpName, dest); err != nil {
		return nil, err
	}
	return b, nil
}
