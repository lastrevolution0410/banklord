//go:build windows

package main

import (
	"errors"
	"syscall"
	"unsafe"
)

var updateKernel = syscall.NewLazyDLL("kernel32.dll")
var createUpdateMutex = updateKernel.NewProc("CreateMutexW")
var waitUpdateMutex = updateKernel.NewProc("WaitForSingleObject")
var releaseUpdateMutex = updateKernel.NewProc("ReleaseMutex")
var closeUpdateMutex = updateKernel.NewProc("CloseHandle")

// Synchronizes multiple installer/background-updater processes. Prevents two
// writers from swapping the same addon directory at the same time.
func acquireUpdateLock() (func(), error) {
	name, err := syscall.UTF16PtrFromString(`Local\Banklord-Update-064`)
	if err != nil {
		return nil, err
	}
	h, _, _ := createUpdateMutex.Call(0, 0, uintptr(unsafe.Pointer(name)))
	if h == 0 {
		return nil, errors.New("Windows-Update-Sperre konnte nicht erstellt werden")
	}
	status, _, _ := waitUpdateMutex.Call(h, 30000)
	if status != 0 && status != 0x80 {
		closeUpdateMutex.Call(h)
		return nil, errors.New("Update-Sperre belegt oder Wartezeit abgelaufen")
	}
	return func() { releaseUpdateMutex.Call(h); closeUpdateMutex.Call(h) }, nil
}
