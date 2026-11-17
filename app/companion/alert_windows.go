//go:build windows

package companion

import (
	"syscall"
	"unsafe"
)

func alertOldCompanion() {
	user32 := syscall.NewLazyDLL("user32.dll")
	messageBox := user32.NewProc("MessageBoxW")
	title, _ := syscall.UTF16PtrFromString("Banklord Companion " + companionVersion)
	msg, _ := syscall.UTF16PtrFromString("Eine aeltere Companion-Version laeuft noch im Hintergrund. Bitte diese im Task-Manager unter Details beenden und danach die neue Version erneut starten.\n\nEs wurde KEINE alte Version geoeffnet. Deine Einstellungen bleiben erhalten.")
	_, _, _ = messageBox.Call(0, uintptr(unsafe.Pointer(msg)), uintptr(unsafe.Pointer(title)), 0x30)
}
