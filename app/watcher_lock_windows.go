//go:build windows

package main

import (
	"errors"
	"syscall"
	"time"
	"unsafe"
)

// Exactly one background updater per Windows session. A freshly self-updated
// process waits here until its predecessor has exited.
func acquireWatcherLock(wait time.Duration) (func(), error) {
	name, err := syscall.UTF16PtrFromString(`Local\Banklord-Watcher`)
	if err != nil {
		return nil, err
	}
	h, _, _ := createUpdateMutex.Call(0, 0, uintptr(unsafe.Pointer(name)))
	if h == 0 {
		return nil, errors.New("Sperre nicht erstellbar")
	}
	status, _, _ := waitUpdateMutex.Call(h, uintptr(wait/time.Millisecond))
	if status != 0 && status != 0x80 {
		closeUpdateMutex.Call(h)
		return nil, errors.New("anderer Update-Dienst aktiv")
	}
	return func() { releaseUpdateMutex.Call(h); closeUpdateMutex.Call(h) }, nil
}
