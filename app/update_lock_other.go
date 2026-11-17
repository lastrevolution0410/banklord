//go:build !windows

package main

import "sync"

var updateLock sync.Mutex

func acquireUpdateLock() (func(), error) { updateLock.Lock(); return updateLock.Unlock, nil }
