//go:build !windows

package main

import "time"

func acquireWatcherLock(time.Duration) (func(), error) { return func() {}, nil }
