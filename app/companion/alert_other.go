//go:build !windows

package companion

import "fmt"

func alertOldCompanion() {
	fmt.Println("Eine alte Companion-Version laeuft noch. Bitte beenden und erneut starten.")
}
