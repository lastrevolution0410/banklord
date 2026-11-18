//go:build windows

package companion

import (
	"encoding/base64"
	"errors"
	"syscall"
	"unsafe"
)

type blob struct {
	Size uint32
	Data *byte
}

var crypt32 = syscall.NewLazyDLL("crypt32.dll")
var cryptProtect = crypt32.NewProc("CryptProtectData")
var cryptUnprotect = crypt32.NewProc("CryptUnprotectData")
var kernel32 = syscall.NewLazyDLL("kernel32.dll")
var localFree = kernel32.NewProc("LocalFree")

func sealed(data []byte, decrypt bool) ([]byte, error) {
	if len(data) == 0 {
		return nil, errors.New("Leeres Secret")
	}
	in := blob{uint32(len(data)), &data[0]}
	var out blob
	fn := cryptProtect
	if decrypt {
		fn = cryptUnprotect
	}
	// DPAPI CURRENT_USER scope, UI_FORBIDDEN; no local machine shared secret.
	r, _, err := fn.Call(uintptr(unsafe.Pointer(&in)), 0, 0, 0, 0, 1, uintptr(unsafe.Pointer(&out)))
	if r == 0 {
		return nil, err
	}
	defer localFree.Call(uintptr(unsafe.Pointer(out.Data)))
	if out.Size == 0 {
		return nil, errors.New("DPAPI lieferte leere Daten")
	}
	return append([]byte(nil), unsafe.Slice(out.Data, int(out.Size))...), nil
}
func protectToken(s string) (string, error) {
	out, err := sealed([]byte(s), false)
	return base64.StdEncoding.EncodeToString(out), err
}
func unprotectToken(s string) (string, error) {
	raw, err := base64.StdEncoding.DecodeString(s)
	if err != nil {
		return "", err
	}
	out, err := sealed(raw, true)
	return string(out), err
}

// protectNamed: DPAPI blobs are self-contained, the name is not needed.
func protectNamed(_ string, s string) (string, error) { return protectToken(s) }
